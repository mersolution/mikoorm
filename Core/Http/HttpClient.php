<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 *
 * HttpClient - REST client on cURL
 * Similar to mersolutionCore HttpClientHelper.cs
 *
 * - one reusable cURL handle per client: keep-alive connections, shared DNS and TLS session cache
 * - pool(): parallel requests with a concurrency limit (curl_multi)
 * - retries with backoff (Retry-After aware, safe for non-idempotent methods)
 * - JSON / form / multipart bodies, query parameters, file downloads
 * - only http:// and https:// URLs, header injection checks
 */

namespace Miko\Core\Http;

require_once __DIR__ . '/HttpException.php';
require_once __DIR__ . '/HttpResponse.php';
require_once __DIR__ . '/../Async/LoopParticipant.php';
require_once __DIR__ . '/../Async/CallbackParticipant.php';
require_once __DIR__ . '/../Async/EventLoop.php';
require_once __DIR__ . '/../Async/Future.php';
require_once __DIR__ . '/../Async/Deferred.php';
require_once __DIR__ . '/../Async/Async.php';

use Miko\Core\Async\CallbackParticipant;
use Miko\Core\Async\Deferred;
use Miko\Core\Async\EventLoop;
use Miko\Core\Async\Future;

class HttpClient
{
    private const IDEMPOTENT = ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'];

    /** cURL errors where the request never reached the server (resolve proxy / host, connect) - retried for every method */
    private const NOT_SENT_ERRORS = [5, 6, 7];

    /** cURL errors retried for idempotent requests (timeouts, TLS handshake, empty reply, send/recv errors) */
    private const TRANSIENT_ERRORS = [5, 6, 7, 18, 28, 35, 52, 55, 56];

    /** HTTP status codes retried for idempotent requests */
    private const TRANSIENT_STATUS = [429, 500, 502, 503, 504];

    private array $config;

    /** @var array<string, array{0: string, 1: string}> lower-case name => [name, value] */
    private array $defaultHeaders = [];

    private ?string $bearerToken = null;
    private ?string $basicAuth = null;

    /** Reused for every synchronous request: keeps connections alive between calls */
    private ?\CurlHandle $handle = null;
    private bool $handleBusy = false;

    /** Reused by pool(): keeps its connection cache between calls */
    private ?\CurlMultiHandle $multiHandle = null;

    /** DNS + TLS session cache shared by every handle of the process */
    private static ?\CurlShareHandle $share = null;

    /** *Async() requests: own multi handle, queue, running transfers, retries waiting for their delay */
    private ?\CurlMultiHandle $asyncMulti = null;
    private array $asyncQueue = [];
    private array $asyncActive = [];
    private array $asyncWaiting = [];
    private ?CallbackParticipant $participant = null;

    /**
     * Options:
     *  base_url, timeout (s, float), connect_timeout (s, float), verify_ssl, ca_bundle, follow_redirects,
     *  max_redirects, user_agent, proxy, http_version ('1.1' | '2'), headers, bearer_token,
     *  basic_auth ['username' => .., 'password' => ..], retries, retry_delay (ms), retry_max_delay (ms),
     *  concurrency (pool limit)
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'base_url' => '',
            'timeout' => 30,
            'connect_timeout' => 10,
            'verify_ssl' => true,
            'ca_bundle' => null,
            'follow_redirects' => true,
            'max_redirects' => 5,
            'user_agent' => 'MikoORM-HttpClient/' . (class_exists(\Miko\Core\Version::class) ? \Miko\Core\Version::VERSION : '2'),
            'proxy' => null,
            'http_version' => null,
            'retries' => 0,
            'retry_delay' => 200,
            'retry_max_delay' => 10000,
            'concurrency' => 10,
        ], $config);

        if (!empty($config['bearer_token'])) {
            $this->bearerToken = (string) $config['bearer_token'];
        }

        if (!empty($config['basic_auth'])) {
            $this->setBasicAuth((string) $config['basic_auth']['username'], (string) $config['basic_auth']['password']);
        }

        if (!empty($config['headers'])) {
            foreach ($this->normalizeHeaders($config['headers']) as $lower => $pair) {
                $this->defaultHeaders[$lower] = $pair;
            }
        }
    }

    /**
     * Create client with base URL
     */
    public static function create(string $baseUrl = '', array $config = []): self
    {
        $config['base_url'] = $baseUrl;
        return new self($config);
    }

    // ========================================
    // Request methods
    // ========================================

    public function get(string $url, array $headers = [], array $query = []): HttpResponse
    {
        return $this->request('GET', $url, null, $headers, ['query' => $query]);
    }

    /**
     * GET and return the decoded JSON body (null when the request failed or the body is not JSON)
     */
    public function getJson(string $url, array $headers = [], array $query = []): ?array
    {
        $response = $this->get($url, $headers, $query);
        return $response->ok() ? $response->json() : null;
    }

    public function head(string $url, array $headers = []): HttpResponse
    {
        return $this->request('HEAD', $url, null, $headers);
    }

    /**
     * POST; arrays and objects are sent as JSON, strings as they are
     */
    public function post(string $url, mixed $data = null, array $headers = []): HttpResponse
    {
        return $this->request('POST', $url, $data, $headers);
    }

    /**
     * POST application/x-www-form-urlencoded
     */
    public function postForm(string $url, array $data, array $headers = []): HttpResponse
    {
        return $this->request('POST', $url, $data, $headers, ['form' => true]);
    }

    /**
     * POST multipart/form-data; pass files as \CURLFile (or new \CURLStringFile)
     */
    public function postMultipart(string $url, array $data, array $headers = []): HttpResponse
    {
        return $this->request('POST', $url, $data, $headers, ['multipart' => true]);
    }

    public function put(string $url, mixed $data = null, array $headers = []): HttpResponse
    {
        return $this->request('PUT', $url, $data, $headers);
    }

    public function patch(string $url, mixed $data = null, array $headers = []): HttpResponse
    {
        return $this->request('PATCH', $url, $data, $headers);
    }

    public function delete(string $url, array $headers = []): HttpResponse
    {
        return $this->request('DELETE', $url, null, $headers);
    }

    /**
     * Stream the response body into a file (no memory use for large files).
     * The file is written as "<path>.part" and renamed only after a 2xx response.
     */
    public function download(string $url, string $path, array $headers = [], array $query = []): HttpResponse
    {
        return $this->request('GET', $url, null, $headers, ['query' => $query, 'sink' => $path]);
    }

    /**
     * Send one request.
     *
     * @param mixed $body    null, string (raw), array / object (JSON; form or multipart with the options below)
     * @param array $options query (array), form (bool), multipart (bool), sink (file path), timeout,
     *                       connect_timeout (s), retries, retry_delay (ms)
     */
    public function request(string $method, string $url, mixed $body = null, array $headers = [], array $options = []): HttpResponse
    {
        $job = $this->prepare($method, $url, $body, $headers, $options);

        while (true) {
            $response = $this->execute($job);

            if ($job['attempt'] >= $job['retries'] || !$this->shouldRetry($job, $response)) {
                return $response;
            }

            $job['attempt']++;
            usleep($this->retryDelay($job, $response) * 1000);
        }
    }

    // ========================================
    // Parallel requests (curl_multi)
    // ========================================

    /**
     * Run requests in parallel, at most $concurrency at a time. Keys are kept, results come back in input order.
     *
     * Each request is a URL string or an array:
     *   ['method' => 'GET', 'url' => '...', 'headers' => [], 'query' => [], 'body' => mixed,
     *    'json' => mixed, 'form' => array, 'multipart' => array, 'sink' => path, 'timeout' => s, 'retries' => n]
     *
     * A failed request never throws: check $response->ok() / ->error for each key.
     *
     * @return array<array-key, HttpResponse>
     */
    public function pool(array $requests, ?int $concurrency = null): array
    {
        if ($requests === []) {
            return [];
        }

        $concurrency = max(1, $concurrency ?? (int) $this->config['concurrency']);

        // validate everything before the first byte is sent
        $jobs = [];
        foreach ($requests as $key => $request) {
            $jobs[$key] = $this->prepareSpec(is_array($request) ? $request : ['url' => (string) $request]);
        }

        $multi = $this->multiHandle ??= curl_multi_init();
        $queue = array_keys($jobs);
        $waiting = [];   // key => time when the retry may start
        $active = [];    // spl_object_id(handle) => [key, handle, header state, file pointer]
        $results = [];

        try {
            while ($queue !== [] || $waiting !== [] || $active !== []) {
                $now = microtime(true);
                foreach ($waiting as $key => $at) {
                    if ($at <= $now) {
                        $queue[] = $key;
                        unset($waiting[$key]);
                    }
                }

                while ($queue !== [] && count($active) < $concurrency) {
                    $key = array_shift($queue);
                    [$ch, $state, $fp] = $this->createHandle($jobs[$key]);
                    $code = curl_multi_add_handle($multi, $ch);
                    if ($code !== CURLM_OK) {
                        if ($fp) {
                            fclose($fp);
                        }
                        throw new HttpException('curl_multi_add_handle failed: ' . curl_multi_strerror($code));
                    }
                    $active[spl_object_id($ch)] = [$key, $ch, $state, $fp];
                }

                if ($active === []) {
                    // only delayed retries left
                    usleep(max(1000, (int) ((min($waiting) - microtime(true)) * 1000000)));
                    continue;
                }

                do {
                    $code = curl_multi_exec($multi, $running);
                } while ($code === CURLM_CALL_MULTI_PERFORM);

                if ($code !== CURLM_OK) {
                    throw new HttpException('curl_multi_exec failed: ' . curl_multi_strerror($code));
                }

                // the error number of a finished transfer is only available here, not from curl_errno()
                while (($done = curl_multi_info_read($multi)) !== false) {
                    if ($done['msg'] !== CURLMSG_DONE) {
                        continue;
                    }

                    $id = spl_object_id($done['handle']);
                    if (!isset($active[$id])) {
                        continue;
                    }

                    [$key, $ch, $state, $fp] = $active[$id];
                    unset($active[$id]);
                    curl_multi_remove_handle($multi, $ch);

                    $job = $jobs[$key];
                    $errno = (int) $done['result'];
                    $content = $job['sink'] === null ? (string) curl_multi_getcontent($ch) : '';
                    $response = $this->buildResponse($ch, $content, $state, $errno);
                    $this->finishSink($job, $fp, $response);

                    if ($job['attempt'] < $job['retries'] && $this->shouldRetry($job, $response)) {
                        $jobs[$key]['attempt']++;
                        $waiting[$key] = microtime(true) + $this->retryDelay($jobs[$key], $response) / 1000;
                        continue;
                    }

                    $results[$key] = $response;
                }

                if ($active !== [] && $running > 0) {
                    $timeout = $waiting === [] ? 1.0 : max(0.001, min(1.0, min($waiting) - microtime(true)));
                    if (curl_multi_select($multi, $timeout) === -1) {
                        usleep(1000);
                    }
                }
            }
        } finally {
            foreach ($active as [, $ch, , $fp]) {
                curl_multi_remove_handle($multi, $ch);
                if ($fp) {
                    fclose($fp);
                }
            }
        }

        $ordered = [];
        foreach ($jobs as $key => $_) {
            $ordered[$key] = $results[$key];
        }
        return $ordered;
    }

    /**
     * 1.x name of pool()
     *
     * @return array<array-key, HttpResponse>
     */
    public function multi(array $requests): array
    {
        return $this->pool($requests);
    }

    // ========================================
    // Async requests (Future<HttpResponse>)
    // ========================================
    //
    // The request starts right away; await() the future, or wait for several
    // together (database queries included): Async::all([$http->getAsync(..), User::countAsync()]).
    // At most "concurrency" requests run at once, the rest wait in a queue.
    // A failed request resolves to a failed HttpResponse (check ->ok()), it does not throw.

    public function getAsync(string $url, array $headers = [], array $query = []): Future
    {
        return $this->requestAsync('GET', $url, null, $headers, ['query' => $query]);
    }

    public function postAsync(string $url, mixed $data = null, array $headers = []): Future
    {
        return $this->requestAsync('POST', $url, $data, $headers);
    }

    public function putAsync(string $url, mixed $data = null, array $headers = []): Future
    {
        return $this->requestAsync('PUT', $url, $data, $headers);
    }

    public function patchAsync(string $url, mixed $data = null, array $headers = []): Future
    {
        return $this->requestAsync('PATCH', $url, $data, $headers);
    }

    public function deleteAsync(string $url, array $headers = []): Future
    {
        return $this->requestAsync('DELETE', $url, null, $headers);
    }

    public function downloadAsync(string $url, string $path, array $headers = [], array $query = []): Future
    {
        return $this->requestAsync('GET', $url, null, $headers, ['query' => $query, 'sink' => $path]);
    }

    /**
     * request() as a Future<HttpResponse>; invalid input (URL, header, method) throws right away
     */
    public function requestAsync(string $method, string $url, mixed $body = null, array $headers = [], array $options = []): Future
    {
        $job = $this->prepare($method, $url, $body, $headers, $options);
        $job['deferred'] = new Deferred();

        $this->asyncQueue[] = $job;
        EventLoop::register($this->participant());
        $this->asyncDispatch();

        return $job['deferred']->future();
    }

    // ========================================
    // Retry
    // ========================================

    /**
     * Retry failed requests of this client: connection errors, timeouts, 429, 500, 502, 503, 504.
     * Non-idempotent requests (POST, PATCH) are only retried when they never reached the server.
     * Delay grows exponentially from $delayMs (with jitter) and honours Retry-After.
     */
    public function retry(int $times, int $delayMs = 200): self
    {
        $this->config['retries'] = max(0, $times);
        $this->config['retry_delay'] = max(0, $delayMs);
        return $this;
    }

    /**
     * 1.x helper: retry any method on connection errors and 5xx / 429, linear backoff ($delayMs * attempt)
     */
    public function withRetry(
        string $method,
        string $url,
        mixed $body = null,
        array $headers = [],
        int $maxRetries = 3,
        int $delayMs = 1000
    ): HttpResponse {
        return $this->request($method, $url, $body, $headers, [
            'retries' => max(0, $maxRetries),
            'retry_delay' => max(0, $delayMs),
            'retry_any_method' => true,
            'retry_linear' => true,
        ]);
    }

    // ========================================
    // Configuration
    // ========================================

    public function setBearerToken(string $token): self
    {
        $this->bearerToken = $token;
        $this->basicAuth = null;
        return $this;
    }

    public function setBasicAuth(string $username, string $password): self
    {
        $this->basicAuth = base64_encode("{$username}:{$password}");
        $this->bearerToken = null;
        return $this;
    }

    /**
     * Add a default header (sent with every request)
     */
    public function addHeader(string $name, string $value): self
    {
        foreach ($this->normalizeHeaders([$name => $value]) as $lower => $pair) {
            $this->defaultHeaders[$lower] = $pair;
        }
        return $this;
    }

    public function removeHeader(string $name): self
    {
        unset($this->defaultHeaders[strtolower($name)]);
        return $this;
    }

    public function setTimeout(float $seconds, ?float $connectSeconds = null): self
    {
        $this->config['timeout'] = $seconds;
        if ($connectSeconds !== null) {
            $this->config['connect_timeout'] = $connectSeconds;
        }
        return $this;
    }

    public function setUserAgent(string $userAgent): self
    {
        $this->assertHeaderValue($userAgent);
        $this->config['user_agent'] = $userAgent;
        return $this;
    }

    /**
     * Release the cURL handles (connections close). The client can still be used afterwards.
     * Async requests that are still queued or running fail.
     */
    public function close(): void
    {
        $error = new HttpException('The HTTP client was closed.');
        foreach ($this->asyncActive as [$job, $ch, , $fp]) {
            if ($this->asyncMulti !== null) {
                curl_multi_remove_handle($this->asyncMulti, $ch);
            }
            if ($fp) {
                fclose($fp);
            }
            $job['deferred']->reject($error);
        }
        foreach (array_merge($this->asyncQueue, array_column($this->asyncWaiting, 0)) as $job) {
            $job['deferred']->reject($error);
        }
        $this->asyncQueue = $this->asyncActive = $this->asyncWaiting = [];
        if ($this->participant !== null) {
            EventLoop::unregister($this->participant);
        }

        $this->handle = null;
        $this->multiHandle = null;
        $this->asyncMulti = null;
    }

    /**
     * Build URL with query parameters
     */
    public static function buildUrlWithParams(string $url, array $params): string
    {
        if (empty($params)) {
            return $url;
        }

        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986) . $fragment;
    }

    // ========================================
    // Internals
    // ========================================

    /**
     * @return array<string, mixed> job: method, url, curl options, retry settings, sink
     */
    private function prepare(string $method, string $url, mixed $body, array $headers, array $options): array
    {
        $method = strtoupper(trim($method));
        if (!preg_match('/^[A-Z]{1,20}$/', $method)) {
            throw new \InvalidArgumentException("Invalid HTTP method: {$method}");
        }

        $fullUrl = $this->buildUrl($url, (array) ($options['query'] ?? []));
        $headerMap = $this->defaultHeaders;
        foreach ($this->normalizeHeaders($headers) as $lower => $pair) {
            $headerMap[$lower] = $pair;
        }

        $curl = [
            CURLOPT_URL => $fullUrl,
            CURLOPT_TIMEOUT_MS => (int) round((float) ($options['timeout'] ?? $this->config['timeout']) * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round((float) ($options['connect_timeout'] ?? $this->config['connect_timeout']) * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => (bool) $this->config['follow_redirects'],
            CURLOPT_MAXREDIRS => (int) $this->config['max_redirects'],
            CURLOPT_SSL_VERIFYPEER => (bool) $this->config['verify_ssl'],
            CURLOPT_SSL_VERIFYHOST => $this->config['verify_ssl'] ? 2 : 0,
            CURLOPT_ENCODING => '', // gzip / deflate / br, whatever libcurl supports
            CURLOPT_USERAGENT => (string) $this->config['user_agent'],
        ];

        // only http(s), also after redirects: no file://, gopher://, dict://...
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $curl[CURLOPT_PROTOCOLS_STR] = 'http,https';
            $curl[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
        } else {
            $curl[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            $curl[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        if (!empty($this->config['ca_bundle'])) {
            $curl[CURLOPT_CAINFO] = (string) $this->config['ca_bundle'];
        }
        if (!empty($this->config['proxy'])) {
            $curl[CURLOPT_PROXY] = (string) $this->config['proxy'];
        }
        $httpVersion = match ((string) $this->config['http_version']) {
            '1.0' => CURL_HTTP_VERSION_1_0,
            '1.1' => CURL_HTTP_VERSION_1_1,
            '2', '2.0' => CURL_HTTP_VERSION_2TLS,
            default => null,
        };
        if ($httpVersion !== null) {
            $curl[CURLOPT_HTTP_VERSION] = $httpVersion;
        }

        $payload = $this->encodeBody($body, $options, $headerMap);

        // method: POST keeps libcurl's browser-like redirect handling (301/302/303 -> GET)
        if ($method === 'GET' && $payload === null) {
            $curl[CURLOPT_HTTPGET] = true;
        } elseif ($method === 'HEAD') {
            $curl[CURLOPT_NOBODY] = true;
            $payload = null;
        } elseif ($method === 'POST') {
            $curl[CURLOPT_POST] = true;
            $payload ??= '';
        } else {
            $curl[CURLOPT_CUSTOMREQUEST] = $method;
        }

        if ($payload !== null) {
            $curl[CURLOPT_POSTFIELDS] = $payload;
        }

        // headers
        if ($this->bearerToken !== null) {
            $headerMap['authorization'] = ['Authorization', 'Bearer ' . $this->bearerToken];
        } elseif ($this->basicAuth !== null) {
            $headerMap['authorization'] = ['Authorization', 'Basic ' . $this->basicAuth];
        }
        $headerMap['accept'] ??= ['Accept', 'application/json'];
        // no "Expect: 100-continue" round trip before large bodies
        $headerMap['expect'] ??= ['Expect', ''];

        $lines = [];
        foreach ($headerMap as [$name, $value]) {
            $lines[] = $value === '' ? "{$name}:" : "{$name}: {$value}";
        }
        $curl[CURLOPT_HTTPHEADER] = $lines;

        $retryAnyMethod = !empty($options['retry_any_method']);

        return [
            'method' => $method,
            'curl' => $curl,
            'sink' => isset($options['sink']) ? (string) $options['sink'] : null,
            'attempt' => 0,
            'retries' => max(0, (int) ($options['retries'] ?? $this->config['retries'])),
            'retry_delay' => max(0, (int) ($options['retry_delay'] ?? $this->config['retry_delay'])),
            'retry_linear' => !empty($options['retry_linear']),
            'retry_any_method' => $retryAnyMethod,
            'idempotent' => $retryAnyMethod || in_array($method, self::IDEMPOTENT, true),
        ];
    }

    /**
     * pool() request array -> job
     */
    private function prepareSpec(array $spec): array
    {
        $options = [];
        foreach (['query', 'sink', 'timeout', 'connect_timeout', 'retries', 'retry_delay'] as $name) {
            if (array_key_exists($name, $spec)) {
                $options[$name] = $spec[$name];
            }
        }

        $body = $spec['body'] ?? null;
        if (array_key_exists('json', $spec)) {
            $body = $spec['json'];
        } elseif (isset($spec['form'])) {
            $body = (array) $spec['form'];
            $options['form'] = true;
        } elseif (isset($spec['multipart'])) {
            $body = (array) $spec['multipart'];
            $options['multipart'] = true;
        }

        return $this->prepare(
            (string) ($spec['method'] ?? 'GET'),
            (string) ($spec['url'] ?? ''),
            $body,
            (array) ($spec['headers'] ?? []),
            $options
        );
    }

    /**
     * Run one job on the reusable handle (keep-alive)
     */
    private function execute(array $job): HttpResponse
    {
        // a request started from inside a cURL callback must not reset the running handle
        $reuse = !$this->handleBusy;
        $ch = $reuse ? ($this->handle ??= curl_init()) : curl_init();

        $this->handleBusy = true;
        try {
            if ($reuse) {
                curl_reset($ch);
            }
            [, $state, $fp] = $this->configureHandle($ch, $job);

            $content = curl_exec($ch);
            $errno = curl_errno($ch);
            $response = $this->buildResponse($ch, is_string($content) ? $content : '', $state, $errno);
            $this->finishSink($job, $fp, $response);
            return $response;
        } finally {
            if ($reuse) {
                $this->handleBusy = false;
            }
        }
    }

    /**
     * New handle for pool()
     *
     * @return array{0: \CurlHandle, 1: \stdClass, 2: resource|null}
     */
    private function createHandle(array $job): array
    {
        return $this->configureHandle(curl_init(), $job);
    }

    /**
     * @return array{0: \CurlHandle, 1: \stdClass, 2: resource|null}
     */
    private function configureHandle(\CurlHandle $ch, array $job): array
    {
        $state = new \stdClass();
        $state->headers = [];

        $options = $job['curl'];
        $options[CURLOPT_HEADERFUNCTION] = static function ($handle, string $line) use ($state): int {
            $length = strlen($line);
            $line = rtrim($line, "\r\n");

            if (str_starts_with($line, 'HTTP/')) {
                // new response block (redirect, 100 Continue): keep only the final headers
                $state->headers = [];
            } elseif (($colon = strpos($line, ':')) !== false) {
                $state->headers[] = [trim(substr($line, 0, $colon)), trim(substr($line, $colon + 1))];
            }

            return $length;
        };

        $fp = null;
        if ($job['sink'] !== null) {
            $fp = @fopen($job['sink'] . '.part', 'wb');
            if ($fp === false) {
                throw new HttpException('Cannot write the download file: ' . $job['sink']);
            }
            $options[CURLOPT_FILE] = $fp;
        } else {
            $options[CURLOPT_RETURNTRANSFER] = true;
        }

        if (($share = self::share()) !== null) {
            $options[CURLOPT_SHARE] = $share;
        }

        if (!curl_setopt_array($ch, $options)) {
            if ($fp) {
                fclose($fp);
            }
            throw new HttpException('Invalid cURL option: ' . curl_error($ch));
        }

        return [$ch, $state, $fp];
    }

    private function buildResponse(\CurlHandle $ch, string $content, \stdClass $state, int $errno): HttpResponse
    {
        $info = curl_getinfo($ch);
        // new connections opened for this transfer (0 = an existing keep-alive connection was reused)
        $info['num_connects'] = (int) curl_getinfo($ch, CURLINFO_NUM_CONNECTS);
        $error = $errno !== 0 ? (curl_error($ch) ?: curl_strerror($errno)) : '';

        return HttpResponse::make(
            $errno === 0 ? (int) ($info['http_code'] ?? 0) : 0,
            $content,
            $state->headers,
            $errno,
            $error,
            $info
        );
    }

    /**
     * Close the download file; keep it only for a successful response
     *
     * @param resource|null $fp
     */
    private function finishSink(array $job, $fp, HttpResponse $response): void
    {
        if ($fp === null || $job['sink'] === null) {
            return;
        }

        fclose($fp);
        $part = $job['sink'] . '.part';

        if ($response->ok()) {
            if (is_file($job['sink'])) {
                @unlink($job['sink']);
            }
            if (!@rename($part, $job['sink'])) {
                @unlink($part);
                throw new HttpException('Cannot move the download to ' . $job['sink']);
            }
        } else {
            @unlink($part);
        }
    }

    // ========================================
    // Async loop (driven by EventLoop while a Future is awaited)
    // ========================================

    private function participant(): CallbackParticipant
    {
        return $this->participant ??= new CallbackParticipant(
            fn(): bool => $this->asyncActive !== [] || $this->asyncQueue !== [] || $this->asyncWaiting !== [],
            fn(): int => $this->asyncTick(),
            fn(float $seconds) => $this->asyncWait($seconds)
        );
    }

    /**
     * Start queued requests (and retries whose delay is over) up to the concurrency limit
     */
    private function asyncDispatch(): int
    {
        $now = microtime(true);
        foreach ($this->asyncWaiting as $index => [$job, $at]) {
            if ($at <= $now) {
                $this->asyncQueue[] = $job;
                unset($this->asyncWaiting[$index]);
            }
        }

        $multi = $this->asyncMulti ??= curl_multi_init();
        $limit = max(1, (int) $this->config['concurrency']);
        $started = 0;

        while ($this->asyncQueue !== [] && count($this->asyncActive) < $limit) {
            $job = array_shift($this->asyncQueue);
            $started++;

            try {
                [$ch, $state, $fp] = $this->createHandle($job);
            } catch (\Throwable $e) {
                $job['deferred']->reject($e);
                continue;
            }

            $code = curl_multi_add_handle($multi, $ch);
            if ($code !== CURLM_OK) {
                if ($fp) {
                    fclose($fp);
                }
                $job['deferred']->reject(new HttpException('curl_multi_add_handle failed: ' . curl_multi_strerror($code)));
                continue;
            }

            $this->asyncActive[spl_object_id($ch)] = [$job, $ch, $state, $fp];
        }

        if ($this->asyncActive !== []) {
            do {
                $code = curl_multi_exec($multi, $running);
            } while ($code === CURLM_CALL_MULTI_PERFORM);
        }

        return $started;
    }

    private function asyncTick(): int
    {
        $progress = $this->asyncDispatch() + $this->asyncCollect();
        $progress += $this->asyncDispatch(); // slots freed by finished transfers

        if ($this->asyncActive === [] && $this->asyncQueue === [] && $this->asyncWaiting === []) {
            EventLoop::unregister($this->participant());
        }

        return $progress;
    }

    /**
     * Finished transfers -> resolve their futures (or schedule a retry)
     */
    private function asyncCollect(): int
    {
        if ($this->asyncMulti === null || $this->asyncActive === []) {
            return 0;
        }

        $finished = 0;
        while (($done = curl_multi_info_read($this->asyncMulti)) !== false) {
            if ($done['msg'] !== CURLMSG_DONE) {
                continue;
            }

            $id = spl_object_id($done['handle']);
            if (!isset($this->asyncActive[$id])) {
                continue;
            }

            [$job, $ch, $state, $fp] = $this->asyncActive[$id];
            unset($this->asyncActive[$id]);
            curl_multi_remove_handle($this->asyncMulti, $ch);
            $finished++;

            $response = $this->buildResponse($ch, $job['sink'] === null ? (string) curl_multi_getcontent($ch) : '', $state, (int) $done['result']);

            try {
                $this->finishSink($job, $fp, $response);
            } catch (\Throwable $e) {
                $job['deferred']->reject($e);
                continue;
            }

            if ($job['attempt'] < $job['retries'] && $this->shouldRetry($job, $response)) {
                $job['attempt']++;
                $this->asyncWaiting[] = [$job, microtime(true) + $this->retryDelay($job, $response) / 1000];
                continue;
            }

            $job['deferred']->resolve($response);
        }

        return $finished;
    }

    private function asyncWait(float $seconds): void
    {
        if ($this->asyncWaiting !== []) {
            $next = min(array_column($this->asyncWaiting, 1));
            $seconds = max(0.0, min($seconds, $next - microtime(true)));
        }

        if ($this->asyncActive !== [] && $this->asyncMulti !== null) {
            if (curl_multi_select($this->asyncMulti, $seconds) === -1) {
                usleep(1000);
            }
            do {
                $code = curl_multi_exec($this->asyncMulti, $running);
            } while ($code === CURLM_CALL_MULTI_PERFORM);
        } elseif ($seconds > 0) {
            usleep((int) ($seconds * 1000000));
        }
    }

    private function shouldRetry(array $job, HttpResponse $response): bool
    {
        if ($response->errno !== 0) {
            return in_array($response->errno, self::NOT_SENT_ERRORS, true)
                || ($job['idempotent'] && in_array($response->errno, self::TRANSIENT_ERRORS, true));
        }

        if (!$job['idempotent']) {
            return false;
        }

        return in_array($response->statusCode, self::TRANSIENT_STATUS, true)
            || ($job['retry_any_method'] && $response->statusCode >= 500);
    }

    /**
     * Delay in ms before the next attempt
     */
    private function retryDelay(array $job, HttpResponse $response): int
    {
        $max = max(0, (int) $this->config['retry_max_delay']);
        $base = $job['retry_delay'];

        $retryAfter = $response->header('Retry-After');
        if ($retryAfter !== null) {
            $seconds = ctype_digit($retryAfter) ? (int) $retryAfter : (($time = strtotime($retryAfter)) !== false ? $time - time() : null);
            if ($seconds !== null) {
                return min($max, max(0, $seconds * 1000));
            }
        }

        if ($job['retry_linear']) {
            return min($max, $base * $job['attempt']);
        }

        // exponential with +-25 % jitter so parallel clients do not retry in lock step
        $delay = $base * (2 ** max(0, $job['attempt'] - 1));
        $delay = (int) ($delay * (0.75 + mt_rand(0, 500) / 1000));
        return min($max, $delay);
    }

    /**
     * @return string|array|null POSTFIELDS value
     */
    private function encodeBody(mixed $body, array $options, array &$headerMap): string|array|null
    {
        if ($body === null) {
            return null;
        }

        if (!empty($options['multipart'])) {
            // libcurl writes the multipart Content-Type with the boundary
            unset($headerMap['content-type']);
            return (array) $body;
        }

        if (!empty($options['form'])) {
            $headerMap['content-type'] ??= ['Content-Type', 'application/x-www-form-urlencoded'];
            return is_string($body) ? $body : http_build_query((array) $body, '', '&');
        }

        if (is_string($body)) {
            return $body;
        }

        if (is_array($body) || is_object($body)) {
            $headerMap['content-type'] ??= ['Content-Type', 'application/json'];
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        }

        return (string) $body;
    }

    private function buildUrl(string $url, array $query): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new \InvalidArgumentException('Request URL cannot be empty.');
        }

        if (!preg_match('~^https?://~i', $url)) {
            // any other scheme (file:, ftp:, gopher:, php: ...) is rejected; a relative path cannot start with "x:"
            if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
                throw new \InvalidArgumentException('Only http:// and https:// URLs are supported.');
            }

            $base = rtrim((string) $this->config['base_url'], '/');
            if ($base === '') {
                throw new \InvalidArgumentException('Relative URL without base_url: ' . $url);
            }
            $url = $base . '/' . ltrim($url, '/');

            if (!preg_match('~^https?://~i', $url)) {
                throw new \InvalidArgumentException('Only http:// and https:// URLs are supported.');
            }
        }

        if (preg_match('/[\r\n\0 ]/', $url)) {
            throw new \InvalidArgumentException('URL contains spaces or control characters; encode it first.');
        }

        return self::buildUrlWithParams($url, $query);
    }

    /**
     * Accepts ['Name' => 'value'] and ['Name: value'] and validates both
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                $line = (string) $value;
                $colon = strpos($line, ':');
                if ($colon === false) {
                    throw new \InvalidArgumentException("Invalid header line: {$line}");
                }
                $name = trim(substr($line, 0, $colon));
                $value = trim(substr($line, $colon + 1));
            }

            $name = trim((string) $name);
            $value = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;

            if (!preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name)) {
                throw new \InvalidArgumentException("Invalid header name: {$name}");
            }
            $this->assertHeaderValue($value);

            $normalized[strtolower($name)] = [$name, $value];
        }

        return $normalized;
    }

    private function assertHeaderValue(string $value): void
    {
        if (preg_match('/[\r\n\0]/', $value)) {
            throw new \InvalidArgumentException('Header values must not contain line breaks.');
        }
    }

    private static function share(): ?\CurlShareHandle
    {
        if (self::$share === null && function_exists('curl_share_init')) {
            $share = curl_share_init();
            curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_DNS);
            curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
            self::$share = $share;
        }
        return self::$share;
    }
}
