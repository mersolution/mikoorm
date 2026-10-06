<?php
/**
 * Router for the local test servers started by tests/http.php (php -S 127.0.0.1:<port> http_router.php)
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$port = (int) $_SERVER['SERVER_PORT'];

function send_json(array $data, int $status = 200): bool
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    return true;
}

switch (true) {
    case $path === '/sleep':
        usleep((int) ($_GET['ms'] ?? 1000) * 1000);
        return send_json(['port' => $port]);

    case (bool) preg_match('~^/status/(\d{3})$~', $path, $m):
        if (isset($_GET['retry_after'])) {
            header('Retry-After: ' . $_GET['retry_after']);
        }
        return send_json(['status' => (int) $m[1]], (int) $m[1]);

    case $path === '/flaky':
        // fails ?fail=N times per ?key=..., then answers 200
        $file = sys_get_temp_dir() . '/miko-http-flaky-' . preg_replace('/\W/', '', $_GET['key'] ?? 'x');
        $count = is_file($file) ? (int) file_get_contents($file) : 0;
        file_put_contents($file, (string) ($count + 1));
        if ($count < (int) ($_GET['fail'] ?? 1)) {
            if (isset($_GET['retry_after'])) {
                header('Retry-After: ' . $_GET['retry_after']);
            }
            return send_json(['attempt' => $count + 1], 503);
        }
        return send_json(['attempt' => $count + 1, 'method' => $_SERVER['REQUEST_METHOD']]);

    case $path === '/redirect303':
        header('Location: /echo', true, 303);
        return true;

    case $path === '/redirect-file':
        header('Location: file:///' . str_replace('\\', '/', __FILE__), true, 302);
        return true;

    case $path === '/echo':
        $headers = [];
        foreach (getallheaders() as $name => $value) {
            $headers[strtolower($name)] = $value;
        }
        return send_json([
            'method' => $_SERVER['REQUEST_METHOD'],
            'body' => file_get_contents('php://input'),
            'query' => $_GET,
            'post' => $_POST,
            'files' => array_map(fn($f) => ['name' => $f['name'], 'size' => $f['size']], $_FILES),
            'headers' => $headers,
        ]);

    case $path === '/headers':
        header('X-Custom: one');
        header('Set-Cookie: a=1', false);
        header('Set-Cookie: b=2', false);
        return send_json(['ok' => true]);

    case $path === '/gzip':
        $text = str_repeat('MikoORM gzip ', 1000);
        if (str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip')) {
            header('Content-Encoding: gzip');
            echo gzencode($text);
        } else {
            echo $text;
        }
        return true;

    case $path === '/download':
        header('Content-Type: application/octet-stream');
        echo str_repeat('x', (int) ($_GET['size'] ?? 1024));
        return true;

    case $path === '/xml':
        header('Content-Type: application/xml');
        echo '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Kurlar xmlns="urn:default" xmlns:t="urn:tcmb" Tarih="06.10.2026">'
            . '<t:Currency Kod="USD"><t:ForexBuying>41.25</t:ForexBuying></t:Currency>'
            . '<t:Currency Kod="EUR"><t:ForexBuying>48.10</t:ForexBuying></t:Currency>'
            . '<Note>İşlem</Note>'
            . '</Kurlar>';
        return true;

    case $path === '/xml-bad':
        header('Content-Type: application/xml');
        echo '<root><unclosed></root>';
        return true;

    case $path === '/xml-404':
        http_response_code(404);
        header('Content-Type: application/xml');
        echo '<error><code>NOT_FOUND</code></error>';
        return true;

    case $path === '/soap':
        if (!class_exists('SoapServer')) {
            http_response_code(500);
            echo 'soap extension missing in the test server';
            return true;
        }
        $server = new SoapServer(null, ['uri' => 'urn:miko-test']);
        $server->addFunction(['sum', 'fail', 'slow']);
        $server->handle();
        return true;
}

http_response_code(404);
echo '{}';
return true;

function sum($params)
{
    $params = (array) $params;
    return (int) $params['a'] + (int) $params['b'];
}

function fail()
{
    throw new SoapFault('Server', 'Boom');
}

function slow($params)
{
    sleep((int) ((array) $params)['seconds']);
    return 'late';
}
