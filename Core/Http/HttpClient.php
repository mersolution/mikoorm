<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * 
 * HttpClient - REST API client with cURL (sync & async support)
 * Similar to mersolutionCore HttpClientHelper.cs
 */

namespace Miko\Core\Http;

class HttpClient
{
    private array $config;
    private array $defaultHeaders = [];
    private ?string $bearerToken = null;
    private ?string $basicAuth = null;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'base_url' => '',
            'timeout' => 30,
            'connect_timeout' => 10,
            'verify_ssl' => true,
            'follow_redirects' => true,
            'max_redirects' => 5,
        ], $config);

        if (!empty($config['bearer_token'])) {
            $this->bearerToken = $config['bearer_token'];
        }

        if (!empty($config['basic_auth'])) {
            $this->basicAuth = base64_encode($config['basic_auth']['username'] . ':' . $config['basic_auth']['password']);
        }

        if (!empty($config['headers'])) {
            $this->defaultHeaders = $config['headers'];
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
    // GET Methods
    // ========================================

    /**
     * Send GET request
     */
    public function get(string $url, array $headers = [], bool $async = false): HttpResponse|int
    {
        return $this->request('GET', $url, null, $headers, $async);
    }

    /**
     * GET and return decoded JSON
     */
    public function getJson(string $url, array $headers = []): ?array
    {
        $response = $this->get($url, $headers);
        return $response->json();
    }

    // ========================================
    // POST Methods
    // ========================================

    /**
     * Send POST request with JSON body
     */
    public function post(string $url, array|string $data = [], array $headers = [], bool $async = false): HttpResponse|int
    {
        if (is_array($data)) {
            $data = json_encode($data);
            $headers['Content-Type'] = 'application/json';
        }
        return $this->request('POST', $url, $data, $headers, $async);
    }

    /**
     * Send POST request with form data
     */
    public function postForm(string $url, array $data, array $headers = [], bool $async = false): HttpResponse|int
    {
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        return $this->request('POST', $url, http_build_query($data), $headers, $async);
    }

    /**
     * Send POST request with multipart form data (file upload)
     */
    public function postMultipart(string $url, array $data, array $headers = []): HttpResponse
    {
        return $this->request('POST', $url, $data, $headers, false, true);
    }

    // ========================================
    // PUT Methods
    // ========================================

    /**
     * Send PUT request with JSON body
     */
    public function put(string $url, array|string $data = [], array $headers = [], bool $async = false): HttpResponse|int
    {
        if (is_array($data)) {
            $data = json_encode($data);
            $headers['Content-Type'] = 'application/json';
        }
        return $this->request('PUT', $url, $data, $headers, $async);
    }

    // ========================================
    // PATCH Methods
    // ========================================

    /**
     * Send PATCH request with JSON body
     */
    public function patch(string $url, array|string $data = [], array $headers = [], bool $async = false): HttpResponse|int
    {
        if (is_array($data)) {
            $data = json_encode($data);
            $headers['Content-Type'] = 'application/json';
        }
        return $this->request('PATCH', $url, $data, $headers, $async);
    }

    // ========================================
    // DELETE Methods
    // ========================================

    /**
     * Send DELETE request
     */
    public function delete(string $url, array $headers = [], bool $async = false): HttpResponse|int
    {
        return $this->request('DELETE', $url, null, $headers, $async);
    }

    // ========================================
    // Core Request Method
    // ========================================

    /**
     * Send HTTP request
     * 
     * @param string $method HTTP method
     * @param string $url URL (relative or absolute)
     * @param string|array|null $body Request body
     * @param array $headers Custom headers
     * @param bool $async Use async (curl_multi)
     * @param bool $multipart Is multipart form data
     * @return HttpResponse|int Response object or curl handle for async
     */
    public function request(
        string $method,
        string $url,
        string|array|null $body = null,
        array $headers = [],
        bool $async = false,
        bool $multipart = false
    ): HttpResponse|int {
        $ch = curl_init();

        // Build full URL
        $fullUrl = $this->buildUrl($url);

        // Set options
        curl_setopt_array($ch, [
            CURLOPT_URL => $fullUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config['timeout'],
            CURLOPT_CONNECTTIMEOUT => $this->config['connect_timeout'],
            CURLOPT_FOLLOWLOCATION => $this->config['follow_redirects'],
            CURLOPT_MAXREDIRS => $this->config['max_redirects'],
            CURLOPT_SSL_VERIFYPEER => $this->config['verify_ssl'],
            CURLOPT_SSL_VERIFYHOST => $this->config['verify_ssl'] ? 2 : 0,
            CURLOPT_HEADER => true,
            CURLOPT_ENCODING => '', // Accept all encodings (gzip, deflate)
        ]);

        // Set method
        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                break;
            case 'PATCH':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }

        // Set body
        if ($body !== null) {
            if ($multipart && is_array($body)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        // Build headers
        $allHeaders = array_merge($this->defaultHeaders, $headers);
        
        if ($this->bearerToken) {
            $allHeaders['Authorization'] = 'Bearer ' . $this->bearerToken;
        } elseif ($this->basicAuth) {
            $allHeaders['Authorization'] = 'Basic ' . $this->basicAuth;
        }

        if (!isset($allHeaders['Accept'])) {
            $allHeaders['Accept'] = 'application/json';
        }

        $headerLines = [];
        foreach ($allHeaders as $key => $value) {
            $headerLines[] = "{$key}: {$value}";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);

        // Async mode - return handle for curl_multi
        if ($async) {
            return (int) $ch;
        }

        // Sync mode - execute and return response
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        return $this->buildResponse($response, $info, $error, $errno);
    }

    /**
     * Build response object from curl result
     */
    private function buildResponse(string|bool $response, array $info, string $error, int $errno): HttpResponse
    {
        $httpResponse = new HttpResponse();
        $httpResponse->statusCode = $info['http_code'] ?? 0;
        $httpResponse->isSuccess = $httpResponse->statusCode >= 200 && $httpResponse->statusCode < 300;
        $httpResponse->error = $error;
        $httpResponse->errno = $errno;
        $httpResponse->info = $info;

        if ($response !== false) {
            $headerSize = $info['header_size'] ?? 0;
            $httpResponse->headers = $this->parseHeaders(substr($response, 0, $headerSize));
            $httpResponse->body = substr($response, $headerSize);
        }

        return $httpResponse;
    }

    /**
     * Parse response headers
     */
    private function parseHeaders(string $headerString): array
    {
        $headers = [];
        $lines = explode("\r\n", $headerString);
        
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $headers[trim($key)] = trim($value);
            }
        }
        
        return $headers;
    }

    /**
     * Build full URL
     */
    private function buildUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $baseUrl = rtrim($this->config['base_url'], '/');
        $url = ltrim($url, '/');

        return $baseUrl ? "{$baseUrl}/{$url}" : $url;
    }

    // ========================================
    // Async Methods (curl_multi)
    // ========================================

    /**
     * Send multiple requests in parallel (async)
     * 
     * @param array $requests Array of ['method' => 'GET', 'url' => '...', 'body' => null, 'headers' => []]
     * @return array Array of HttpResponse objects
     */
    public function multi(array $requests): array
    {
        $multiHandle = curl_multi_init();
        $handles = [];

        // Add all requests
        foreach ($requests as $key => $request) {
            $method = $request['method'] ?? 'GET';
            $url = $request['url'] ?? '';
            $body = $request['body'] ?? null;
            $headers = $request['headers'] ?? [];

            $ch = $this->request($method, $url, $body, $headers, true);
            $handles[$key] = $ch;
            curl_multi_add_handle($multiHandle, $ch);
        }

        // Execute all requests
        do {
            $status = curl_multi_exec($multiHandle, $running);
            if ($running) {
                curl_multi_select($multiHandle);
            }
        } while ($running > 0);

        // Collect responses
        $responses = [];
        foreach ($handles as $key => $ch) {
            $response = curl_multi_getcontent($ch);
            $info = curl_getinfo($ch);
            $error = curl_error($ch);
            $errno = curl_errno($ch);

            $responses[$key] = $this->buildResponse($response, $info, $error, $errno);

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);

        return $responses;
    }

    // ========================================
    // Retry Methods
    // ========================================

    /**
     * Send request with retry policy
     */
    public function withRetry(
        string $method,
        string $url,
        string|array|null $body = null,
        array $headers = [],
        int $maxRetries = 3,
        int $delayMs = 1000
    ): HttpResponse {
        $lastResponse = null;

        for ($i = 0; $i <= $maxRetries; $i++) {
            $response = $this->request($method, $url, $body, $headers);

            // Success or client error (4xx) - don't retry
            if ($response->isSuccess || ($response->statusCode >= 400 && $response->statusCode < 500)) {
                return $response;
            }

            $lastResponse = $response;

            // Wait before retry (exponential backoff)
            if ($i < $maxRetries) {
                usleep($delayMs * 1000 * ($i + 1));
            }
        }

        return $lastResponse;
    }

    // ========================================
    // Helper Methods
    // ========================================

    /**
     * Set Bearer token
     */
    public function setBearerToken(string $token): self
    {
        $this->bearerToken = $token;
        return $this;
    }

    /**
     * Set Basic auth
     */
    public function setBasicAuth(string $username, string $password): self
    {
        $this->basicAuth = base64_encode("{$username}:{$password}");
        return $this;
    }

    /**
     * Add default header
     */
    public function addHeader(string $name, string $value): self
    {
        $this->defaultHeaders[$name] = $value;
        return $this;
    }

    /**
     * Remove default header
     */
    public function removeHeader(string $name): self
    {
        unset($this->defaultHeaders[$name]);
        return $this;
    }

    /**
     * Build URL with query parameters
     */
    public static function buildUrlWithParams(string $url, array $params): string
    {
        if (empty($params)) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . http_build_query($params);
    }
}

/**
 * HTTP Response wrapper
 */
class HttpResponse
{
    public int $statusCode = 0;
    public bool $isSuccess = false;
    public string $body = '';
    public array $headers = [];
    public string $error = '';
    public int $errno = 0;
    public array $info = [];

    /**
     * Get body as JSON array
     */
    public function json(): ?array
    {
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Get body as object
     */
    public function object(): ?object
    {
        return json_decode($this->body);
    }

    /**
     * Throw exception if not successful
     */
    public function throwIfFailed(): self
    {
        if (!$this->isSuccess) {
            $message = $this->error ?: $this->body;
            throw new \RuntimeException("HTTP {$this->statusCode}: {$message}");
        }
        return $this;
    }

    /**
     * Check if response is OK (2xx)
     */
    public function ok(): bool
    {
        return $this->isSuccess;
    }

    /**
     * Check if response failed
     */
    public function failed(): bool
    {
        return !$this->isSuccess;
    }

    /**
     * Get header value
     */
    public function header(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }
}
