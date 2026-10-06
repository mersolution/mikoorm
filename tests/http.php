<?php
/**
 * MikoORM HTTP client tests (HttpClient, XmlClient, SoapClient) against local php -S servers.
 *
 *   php tests/http.php
 *   php -d extension=soap tests/http.php      (SOAP tests run only when the soap extension is loaded)
 */

declare(strict_types=1);

namespace MikoHttpTests;

use Miko\Core\Async\Async;
use Miko\Core\Http\HttpClient;
use Miko\Core\Http\HttpException;
use Miko\Core\Http\HttpResponse;
use Miko\Core\Http\SoapClient;
use Miko\Core\Http\XmlClient;
use Miko\Database\Async\AsyncConnection;
use Miko\Database\ConnectionFactory;

error_reporting(-1);
ini_set('display_errors', '1');

require __DIR__ . '/../autoload.php';

// any warning / notice / deprecation fails the test that caused it
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false; // silenced with @
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    public static int $skip = 0;
    public static array $failed = [];

    public static function group(string $name): void
    {
        echo "\n{$name}\n";
    }

    public static function test(string $name, callable $test): void
    {
        try {
            $test();
            self::$pass++;
            echo "  ok    {$name}\n";
        } catch (SkipTest $e) {
            self::$skip++;
            echo "  skip  {$name} ({$e->getMessage()})\n";
        } catch (\Throwable $e) {
            self::$fail++;
            self::$failed[] = $name;
            echo "  FAIL  {$name}\n        " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
        }
    }
}

final class SkipTest extends \RuntimeException
{
}

function same(mixed $expected, mixed $actual, string $label = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(($label !== '' ? "{$label}: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function ok(bool $condition, string $label = 'condition'): void
{
    if (!$condition) {
        throw new \RuntimeException("failed: {$label}");
    }
}

function throws(callable $callback, string $class): \Throwable
{
    try {
        $callback();
    } catch (\Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw new \RuntimeException("expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
    }
    throw new \RuntimeException("expected {$class}, nothing thrown");
}

function timed(callable $callback, ?float &$seconds): mixed
{
    $start = microtime(true);
    $result = $callback();
    $seconds = microtime(true) - $start;
    return $result;
}

// ============================================================================
// Local servers (php -S is single threaded, so parallel tests use several ports)
// ============================================================================

function freePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr($name, strrpos($name, ':') + 1);
}

$router = __DIR__ . '/fixtures/http_router.php';
$servers = [];
$ports = [];
// the servers need SoapServer when this run has soap (a "module already loaded" warning goes to NUL)
$serverArgs = extension_loaded('soap') ? ['-d', 'extension=soap'] : [];

for ($i = 0; $i < 4; $i++) {
    $port = freePort();
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $process = proc_open(
        array_merge([PHP_BINARY], $serverArgs, ['-S', "127.0.0.1:{$port}", $router]),
        [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
        $pipes
    );
    $servers[] = $process;
    $ports[] = $port;
}

register_shutdown_function(static function () use (&$servers): void {
    foreach ($servers as $process) {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }
    foreach (glob(sys_get_temp_dir() . '/miko-http-flaky-*') ?: [] as $file) {
        @unlink($file);
    }
});

foreach ($ports as $port) {
    $deadline = microtime(true) + 5;
    while (true) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
        if ($socket) {
            fclose($socket);
            break;
        }
        if (microtime(true) > $deadline) {
            fwrite(STDERR, "test server on port {$port} did not start\n");
            exit(1);
        }
        usleep(50000);
    }
}

[$a, $b, $c, $d] = array_map(fn($p) => "http://127.0.0.1:{$p}", $ports);
$closedPort = freePort();
$refused = "http://127.0.0.1:{$closedPort}";
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'miko-http-' . getmypid();
@mkdir($tmp);

echo 'MikoORM ' . \Miko\Core\Version::VERSION . ' HTTP tests on PHP ' . PHP_VERSION . ', libcurl ' . curl_version()['version']
    . (extension_loaded('soap') ? ', soap' : ', no soap') . "\n";

// ============================================================================
// HttpClient
// ============================================================================

T::group('HttpClient');

T::test('GET with base_url, query, JSON body and case-insensitive headers', function () use ($a) {
    $client = HttpClient::create($a . '/');
    $response = $client->get('/echo?x=1', ['X-Test' => 'yes'], ['q' => 'a b', 'list' => [1, 2]]);
    same(200, $response->status());
    ok($response->ok() && $response->successful());
    same('GET', $response->json('method'));
    same(['x' => '1', 'q' => 'a b', 'list' => ['1', '2']], $response->json('query'));
    same('yes', $response->json('headers.x-test'));
    same('application/json', $response->header('content-type'));
    same('application/json', $response->header('CONTENT-TYPE'));
    same(null, $response->json('missing.key'));
    same('d', $response->json('missing', 'd'));
    ok(str_starts_with((string) $response->json('headers.user-agent'), 'MikoORM-HttpClient/'), 'user agent');
});

T::test('connection refused: failed, errno 7, no exception', function () use ($refused) {
    $response = (new HttpClient())->get($refused . '/x');
    same(false, $response->ok());
    same(true, $response->connectionFailed());
    same(7, $response->errno);
    same(0, $response->status());
    ok($response->error !== '', 'error text');
    $e = throws(fn() => $response->throwIfFailed(), HttpException::class);
    same(7, $e->getCode());
    same($response, $e->getResponse());
});

T::test('timeout: errno 28 within the time limit', function () use ($d) {
    $response = timed(fn() => (new HttpClient(['timeout' => 0.3]))->get($d . '/sleep?ms=1000'), $seconds);
    same(28, $response->errno);
    same(false, $response->ok());
    ok($seconds < 0.9, "took {$seconds} s");
    usleep(800000); // let the single threaded server finish the sleep
});

T::test('HTTP 500 is a failure, throwIfFailed() carries status and body', function () use ($a) {
    $response = (new HttpClient())->get($a . '/status/500');
    same(500, $response->status());
    same(false, $response->ok());
    same(true, $response->serverError());
    same(false, $response->connectionFailed());
    $e = throws(fn() => $response->throwIfFailed(), HttpException::class);
    same(500, $e->getCode());
    ok(str_contains($e->getMessage(), 'HTTP 500'), 'message');
    same(true, (new HttpClient())->get($a . '/status/404')->clientError());
});

T::test('only http(s): file://, ftp://, php:// and redirects to file:// are refused', function () use ($a) {
    $client = new HttpClient();
    foreach (['file:///etc/passwd', 'file:///C:/Windows/win.ini', 'ftp://example.com/x', 'php://filter/resource=x', 'gopher://x'] as $url) {
        throws(fn() => $client->get($url), \InvalidArgumentException::class);
    }
    $response = $client->get($a . '/redirect-file');
    same(false, $response->ok());
    ok(!str_contains($response->body, '<?php'), 'local file must not be read');
    throws(fn() => (new HttpClient())->get('relative/path'), \InvalidArgumentException::class);
    throws(fn() => (new HttpClient(['base_url' => 'file:///tmp']))->get('x'), \InvalidArgumentException::class);
});

T::test('headers as [name => value] and ["Name: value"]; header injection refused', function () use ($a) {
    $client = new HttpClient(['headers' => ['X-Default' => 'd']]);
    $client->addHeader('x-added', 'a');
    $echo = $client->get($a . '/echo', ['X-Assoc' => 'one', 'X-Line: two', 'Accept' => 'text/plain'])->json('headers');
    same('one', $echo['x-assoc']);
    same('two', $echo['x-line']);
    same('d', $echo['x-default']);
    same('a', $echo['x-added']);
    same('text/plain', $echo['accept']);
    ok(!isset($echo['expect']), 'no Expect header');
    throws(fn() => $client->get($a . '/echo', ['X-Bad' => "a\r\nX-Injected: 1"]), \InvalidArgumentException::class);
    throws(fn() => $client->get($a . '/echo', ["Bad Name" => 'x']), \InvalidArgumentException::class);
    $client->removeHeader('X-DEFAULT');
    ok(!isset($client->get($a . '/echo')->json('headers')['x-default']), 'removeHeader is case-insensitive');
});

T::test('bearer / basic auth', function () use ($a) {
    $client = new HttpClient(['bearer_token' => 'T0K']);
    same('Bearer T0K', $client->get($a . '/echo')->json('headers.authorization'));
    $client->setBasicAuth('u', 'p');
    same('Basic ' . base64_encode('u:p'), $client->get($a . '/echo')->json('headers.authorization'));
});

T::test('POST followed by 303 becomes GET without body', function () use ($b) {
    $response = (new HttpClient())->post($b . '/redirect303', ['a' => 1]);
    same('GET', $response->json('method'));
    same('', $response->json('body'));
    ok(str_ends_with($response->effectiveUrl(), '/echo'), 'effective url');
});

T::test('POST JSON / form / multipart / raw, PUT, PATCH, DELETE, HEAD', function () use ($a) {
    $client = new HttpClient();
    $json = $client->post($a . '/echo', ['name' => 'KOBİ3', 'price' => 1.0]);
    same('{"name":"KOBİ3","price":1.0}', $json->json('body'));
    same('application/json', $json->json('headers.content-type'));

    $form = $client->postForm($a . '/echo', ['name' => 'KOBİ3', 'tags' => ['a', 'b']]);
    same(['name' => 'KOBİ3', 'tags' => ['a', 'b']], $form->json('post'));

    $multipart = $client->postMultipart($a . '/echo', ['title' => 'doc', 'file' => new \CURLStringFile('hello', 'a.txt', 'text/plain')]);
    same(['title' => 'doc'], $multipart->json('post'));
    same(['file' => ['name' => 'a.txt', 'size' => 5]], $multipart->json('files'));

    same('<x/>', $client->post($a . '/echo', '<x/>', ['Content-Type' => 'application/xml'])->json('body'));
    same('application/xml', $client->post($a . '/echo', '<x/>', ['content-type' => 'application/xml'])->json('headers.content-type'));
    same('', $client->post($a . '/echo')->json('body'));

    same('PUT', $client->put($a . '/echo', ['a' => 1])->json('method'));
    same('{"a":1}', $client->patch($a . '/echo', ['a' => 1])->json('body'));
    same('DELETE', $client->delete($a . '/echo')->json('method'));
    $head = $client->head($a . '/echo');
    same(200, $head->status());
    same('', $head->body);
});

T::test('response headers: repeated values, final response only', function () use ($a, $b) {
    $response = (new HttpClient())->get($a . '/headers');
    same(['a=1', 'b=2'], $response->headerValues('set-cookie'));
    same('a=1, b=2', $response->header('Set-Cookie'));
    same('one', $response->header('x-custom'));
    same(true, $response->hasHeader('X-CUSTOM'));
    $redirected = (new HttpClient())->get($b . '/redirect303');
    same(null, $redirected->header('Location'), 'redirect headers are dropped');
});

T::test('gzip responses are decoded', function () use ($a) {
    $response = (new HttpClient())->get($a . '/gzip');
    same(str_repeat('MikoORM gzip ', 1000), $response->body);
    same('gzip', $response->header('content-encoding'));
});

T::test('keep-alive: the second request reuses the connection', function () use ($a) {
    $client = new HttpClient();
    $first = $client->get($a . '/echo');
    $second = $client->get($a . '/echo');
    same(1, (int) $first->info['num_connects']);
    if ((int) $second->info['num_connects'] !== 0) {
        throw new SkipTest('test server closed the connection');
    }
});

T::test('download streams to a file, failures leave no file', function () use ($a, $tmp) {
    $client = new HttpClient();
    $file = $tmp . DIRECTORY_SEPARATOR . 'big.bin';
    $response = $client->download($a . '/download', $file, [], ['size' => 3 * 1024 * 1024]);
    same(true, $response->ok());
    same('', $response->body);
    same(3 * 1024 * 1024, filesize($file));
    ok(!is_file($file . '.part'), 'no .part file');

    $missing = $tmp . DIRECTORY_SEPARATOR . 'missing.bin';
    same(false, $client->download($a . '/status/404', $missing)->ok());
    ok(!is_file($missing) && !is_file($missing . '.part'), 'nothing written for 404');
    unlink($file);
});

T::test('retry(): 503 twice then 200 for GET; POST is not retried', function () use ($a) {
    $key = 'get' . mt_rand();
    $response = (new HttpClient())->retry(3, 20)->get($a . '/flaky', [], ['key' => $key, 'fail' => 2]);
    same(200, $response->status());
    same(3, $response->json('attempt'));

    $key = 'post' . mt_rand();
    $post = (new HttpClient())->retry(3, 20)->post($a . '/flaky?key=' . $key . '&fail=2', ['x' => 1]);
    same(503, $post->status(), 'POST must not be repeated after the server answered');

    $refused = (new HttpClient(['retries' => 2, 'retry_delay' => 10]));
    same(7, $refused->post('http://127.0.0.1:1/x')->errno, 'POST retried only while not sent, still fails');
});

T::test('retry honours Retry-After', function () use ($b) {
    $key = 'ra' . mt_rand();
    $response = timed(fn() => (new HttpClient())->retry(2, 10)->get($b . '/flaky', [], ['key' => $key, 'fail' => 1, 'retry_after' => 1]), $seconds);
    same(200, $response->status());
    ok($seconds >= 0.9, "waited {$seconds} s");
});

T::test('withRetry() (1.x) also retries POST on 5xx', function () use ($c) {
    $key = 'legacy' . mt_rand();
    $response = (new HttpClient())->withRetry('POST', $c . '/flaky?key=' . $key . '&fail=2', ['a' => 1], [], 3, 10);
    same(200, $response->status());
    same('POST', $response->json('method'));
});

T::test('pool(): 3 requests of 1 s finish in about 1 s, keys and order kept', function () use ($a, $b, $c) {
    $client = new HttpClient();
    $results = timed(fn() => $client->pool([
        'first' => $a . '/sleep?ms=1000',
        'second' => ['url' => $b . '/sleep', 'query' => ['ms' => 1000]],
        'third' => ['method' => 'GET', 'url' => $c . '/sleep?ms=1000'],
    ]), $seconds);
    ok($seconds < 1.6, "took {$seconds} s");
    same(['first', 'second', 'third'], array_keys($results));
    foreach ($results as $response) {
        ok($response instanceof HttpResponse && $response->ok(), 'each ok');
    }
    same(parse_url($b, PHP_URL_PORT), $results['second']->json('port'));
});

T::test('pool(): per-request errors (refused, 500, ok) with the right errno', function () use ($a, $refused) {
    $results = (new HttpClient())->pool([
        'down' => $refused . '/x',
        'error' => $a . '/status/500',
        'fine' => $a . '/echo',
        'post' => ['method' => 'POST', 'url' => $a . '/echo', 'json' => ['k' => 'v']],
        'form' => ['method' => 'POST', 'url' => $a . '/echo', 'form' => ['k' => 'v']],
    ]);
    same(7, $results['down']->errno);
    same(true, $results['down']->connectionFailed());
    same(500, $results['error']->status());
    same(0, $results['error']->errno);
    same(true, $results['fine']->ok());
    same('{"k":"v"}', $results['post']->json('body'));
    same(['k' => 'v'], $results['form']->json('post'));
});

T::test('pool(): concurrency limit', function () use ($a, $b, $c, $d) {
    $requests = [$a . '/sleep?ms=500', $b . '/sleep?ms=500', $c . '/sleep?ms=500', $d . '/sleep?ms=500'];
    timed(fn() => (new HttpClient())->pool($requests, 2), $limited);
    timed(fn() => (new HttpClient())->pool($requests, 4), $full);
    ok($limited >= 0.95, "concurrency 2 took {$limited} s");
    ok($full < 0.9, "concurrency 4 took {$full} s");
});

T::test('pool(): retries and downloads inside the pool, multi() alias', function () use ($a, $b, $tmp) {
    $key = 'pool' . mt_rand();
    $file = $tmp . DIRECTORY_SEPARATOR . 'pool.bin';
    $results = (new HttpClient())->pool([
        'flaky' => ['url' => $a . '/flaky', 'query' => ['key' => $key, 'fail' => 2], 'retries' => 3, 'retry_delay' => 10],
        'file' => ['url' => $b . '/download?size=1000', 'sink' => $file],
    ]);
    same(3, $results['flaky']->json('attempt'));
    same(1000, filesize($file));
    unlink($file);
    same(200, (new HttpClient())->multi(['x' => ['url' => $a . '/echo']])['x']->status());
    same([], (new HttpClient())->pool([]));
});

T::test('invalid input is rejected before anything is sent', function () use ($a) {
    $client = new HttpClient();
    throws(fn() => $client->request('GE T', $a . '/echo'), \InvalidArgumentException::class);
    throws(fn() => $client->pool(['ok' => $a . '/echo', 'bad' => 'file:///x']), \InvalidArgumentException::class);
    throws(fn() => $client->get($a . "/echo\r\nHost: evil"), \InvalidArgumentException::class);
    throws(fn() => $client->post($a . '/echo', ['bad' => "\xB1\x31"]), \JsonException::class);
});

T::test('getJson(), object(), buildUrlWithParams()', function () use ($a) {
    $client = new HttpClient();
    same('GET', $client->getJson($a . '/echo')['method']);
    same(null, $client->getJson($a . '/status/500'));
    same('GET', $client->get($a . '/echo')->object()->method);
    same(null, HttpResponse::make(200, '5')->object());
    same('http://x/a?b=1&c=d%20e#f', HttpClient::buildUrlWithParams('http://x/a?b=1#f', ['c' => 'd e']));
});

// ============================================================================
// Async HTTP
// ============================================================================

T::group('HttpClient async');

T::test('getAsync(): 3 requests of 1 s finish in about 1 s; futures resolve to HttpResponse', function () use ($a, $b, $c) {
    $client = new HttpClient();
    $results = timed(fn() => Async::all([
        'x' => $client->getAsync($a . '/sleep', [], ['ms' => 1000]),
        'y' => $client->getAsync($b . '/sleep?ms=1000'),
        'z' => $client->postAsync($c . '/echo', ['k' => 'v']),
    ]), $seconds);
    ok($seconds < 1.6, "took {$seconds} s");
    same(['x', 'y', 'z'], array_keys($results));
    same(parse_url($b, PHP_URL_PORT), $results['y']->json('port'));
    same('{"k":"v"}', $results['z']->json('body'));
});

T::test('async failures resolve to failed responses; invalid input throws right away', function () use ($a, $refused) {
    $client = new HttpClient();
    $down = $client->getAsync($refused . '/x')->await();
    same(7, $down->errno);
    same(false, $down->ok());
    same(500, $client->getAsync($a . '/status/500')->await()->status());
    throws(fn() => $client->getAsync('file:///etc/passwd'), \InvalidArgumentException::class);
    same('DELETE', $client->deleteAsync($a . '/echo')->await()->json('method'));
    same('PUT', $client->putAsync($a . '/echo', ['a' => 1])->await()->json('method'));
    same('{"a":1}', $client->patchAsync($a . '/echo', ['a' => 1])->await()->json('body'));
});

T::test('async retries and the concurrency limit', function () use ($a, $b, $c, $d) {
    $key = 'async' . mt_rand();
    $response = (new HttpClient())->retry(3, 20)->getAsync($a . '/flaky', [], ['key' => $key, 'fail' => 2])->await();
    same(3, $response->json('attempt'));

    $requests = fn(HttpClient $client) => [
        $client->getAsync($a . '/sleep?ms=500'), $client->getAsync($b . '/sleep?ms=500'),
        $client->getAsync($c . '/sleep?ms=500'), $client->getAsync($d . '/sleep?ms=500'),
    ];
    $limited = new HttpClient(['concurrency' => 2]);
    timed(fn() => Async::all($requests($limited)), $slow);
    timed(fn() => Async::all($requests(new HttpClient())), $fast);
    ok($slow >= 0.95, "concurrency 2 took {$slow} s");
    ok($fast < 0.9, "concurrency 10 took {$fast} s");
});

T::test('downloadAsync() and then() chains', function () use ($a, $tmp) {
    $file = $tmp . DIRECTORY_SEPARATOR . 'async.bin';
    same(2048, (new HttpClient())->downloadAsync($a . '/download', $file, [], ['size' => 2048])->then(fn() => filesize($file))->await());
    unlink($file);
    same('GET', (new HttpClient())->getAsync($a . '/echo')->then(fn(HttpResponse $r) => $r->json('method'))->await());
});

T::test('database query and HTTP requests wait together in one Async::all()', function () use ($a, $b) {
    $driver = getenv('MIKO_TEST_DRIVER') ?: 'sqlite';
    $config = ['driver' => $driver];
    if ($driver === 'sqlite') {
        $config['database'] = ':memory:';
    } else {
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            if (($value = getenv('MIKO_TEST_' . strtoupper($key))) !== false) {
                $config[$key] = $value;
            }
        }
    }
    $db = ConnectionFactory::make($config);
    $parallel = AsyncConnection::isParallel($db);
    $sql = match ($driver) {
        'pgsql' => 'SELECT 7 AS v, pg_sleep(0.5) AS s',
        'mysql' => 'SELECT 7 AS v, SLEEP(0.5) AS s',
        default => 'SELECT 7 AS v',
    };

    $http = new HttpClient();
    $results = timed(fn() => Async::all([
        'db' => AsyncConnection::select($db, $sql),
        'one' => $http->getAsync($a . '/sleep?ms=500'),
        'two' => $http->getAsync($b . '/sleep?ms=500'),
    ]), $seconds);

    same(7, (int) $results['db'][0]['v']);
    ok($results['one']->ok() && $results['two']->ok(), 'http ok');
    ok($seconds < 1.0, sprintf('%s + 2 HTTP took %.2f s', $parallel ? "{$driver} SLEEP(0.5)" : 'sqlite', $seconds));
    $db->disconnect();
});

// ============================================================================
// XmlClient
// ============================================================================

T::group('XmlClient');

T::test('get(): parse, namespaces, attributes, values', function () use ($a) {
    $xml = XmlClient::create()->get($a . '/xml');
    same(true, $xml->isSuccess());
    same(200, $xml->status());
    same('06.10.2026', $xml->attribute('Tarih'));
    same('41.25', $xml->value('//t:Currency[@Kod="USD"]/t:ForexBuying'));
    same(2, count($xml->find('//t:Currency')));
    $xml->registerNamespace('d', 'urn:default');
    same('İşlem', $xml->value('//d:Note'));
    same([], $xml->find('//[invalid'));
});

T::test('invalid XML, HTTP errors and connection errors are failures without warnings', function () use ($a, $refused) {
    $client = new XmlClient();
    $bad = $client->get($a . '/xml-bad');
    same(false, $bad->isSuccess());
    ok(str_starts_with((string) $bad->error(), 'Failed to parse XML: '), (string) $bad->error());

    $missing = $client->get($a . '/xml-404');
    same(false, $missing->isSuccess());
    same('HTTP 404', $missing->error());
    same('NOT_FOUND', $missing->value('//code'), 'error document is still readable');

    $down = $client->getWithCurl($refused . '/x');
    same(false, $down->isSuccess());
    ok(!str_contains((string) $down->error(), 'Failed to fetch URL'), 'no URL in the message');
});

T::test('post(), parse(), pool()', function () use ($a, $b) {
    $client = new XmlClient();
    $sent = $client->http()->post($a . '/echo', '<r/>', ['Content-Type' => 'application/xml; charset=utf-8']);
    same('<r/>', $sent->json('body'));
    same('a', XmlClient::parse('<r><x>a</x></r>')->value('//x'));
    same(false, XmlClient::parse('')->isSuccess());
    $doc = new \DOMDocument();
    $doc->loadXML('<r><y>1</y></r>');
    same(false, $client->post($a . '/echo', $doc)->isSuccess(), 'JSON echo is not XML');

    $results = $client->pool(['x' => $a . '/xml', 'y' => $b . '/xml']);
    same(['x', 'y'], array_keys($results));
    same('48.10', $results['y']->value('//t:Currency[@Kod="EUR"]/t:ForexBuying'));
});

T::test('external entities are not loaded (XXE)', function () use ($tmp) {
    $secret = $tmp . DIRECTORY_SEPARATOR . 'secret.txt';
    file_put_contents($secret, 'TOP-SECRET');
    $uri = 'file:///' . str_replace('\\', '/', $secret);
    $xml = XmlClient::parse('<?xml version="1.0"?><!DOCTYPE r [<!ENTITY s SYSTEM "' . $uri . '">]><r>&s;</r>');
    ok(!str_contains((string) $xml->value(), 'TOP-SECRET'), 'entity must not be expanded');
    unlink($secret);
});

// ============================================================================
// SoapClient
// ============================================================================

T::group('SoapClient');

$soap = static function (string $base, array $options = []): SoapClient {
    if (!extension_loaded('soap')) {
        throw new SkipTest('run with -d extension=soap');
    }
    return new SoapClient(null, $options + ['location' => $base . '/soap', 'uri' => 'urn:miko-test']);
};

T::test('call() returns the result and keeps the trace', function () use ($soap, $c) {
    $client = $soap($c);
    $response = $client->call('sum', ['a' => 2, 'b' => 3]);
    same(true, $response->isSuccess(), (string) $response->error());
    same(5, $response->data());
    ok($response->duration() > 0, 'duration');
    ok(str_contains((string) $client->getLastRequest(), 'sum'), 'last request');
    same(7, $client->sum(['a' => 3, 'b' => 4])->data(), '__call');
});

T::test('SOAP fault becomes a failed response with fault code', function () use ($soap, $c) {
    $client = $soap($c);
    $response = $client->call('fail');
    same(false, $response->isSuccess());
    same('Boom', $response->error());
    ok(str_contains((string) $response->faultCode(), 'Server'), (string) $response->faultCode());
    ok($client->getLastError() instanceof \SoapFault, 'last error');
    throws(fn() => $response->throwIfFailed(), \RuntimeException::class);
});

T::test('unreachable WSDL is a failed response, not an uncaught exception', function () use ($refused) {
    if (!extension_loaded('soap')) {
        throw new SkipTest('run with -d extension=soap');
    }
    $response = (new SoapClient($refused . '/service?wsdl', ['timeout' => 2]))->call('anything');
    same(false, $response->isSuccess());
    ok((string) $response->error() !== '', 'error text');
    throws(fn() => new SoapClient(null), \InvalidArgumentException::class);
});

T::test('response timeout applies and default_socket_timeout is restored', function () use ($soap, $d) {
    $before = ini_get('default_socket_timeout');
    $client = $soap($d, ['timeout' => 1]);
    $response = timed(fn() => $client->call('slow', ['seconds' => 3]), $seconds);
    same(false, $response->isSuccess());
    ok($seconds < 2.5, "took {$seconds} s");
    same($before, ini_get('default_socket_timeout'));
});

// ============================================================================

@rmdir($tmp);
echo "\n" . T::$pass . ' passed, ' . T::$fail . ' failed' . (T::$skip ? ', ' . T::$skip . ' skipped' : '') . "\n";
if (T::$fail > 0) {
    echo 'Failed: ' . implode(' | ', T::$failed) . "\n";
}
exit(T::$fail > 0 ? 1 : 0);
