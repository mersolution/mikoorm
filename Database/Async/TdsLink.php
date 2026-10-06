<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\Async;

use Miko\Database\Exceptions\DatabaseException;

/**
 * @internal One SQL Server connection that speaks TDS 7.4 in plain PHP, so SQL Server
 * queries can run in parallel (pdo_sqlsrv has no async API). Needs sockets, mbstring
 * and openssl.
 *
 * - host, "host,port", "host\instance" (SQL Browser lookup), port, connect_timeout
 * - SQL Server login; encrypt / trust_server_certificate like the ODBC driver: without
 *   encrypt only the login is encrypted, with encrypt (or a server that forces it) all
 *   traffic, the certificate checked unless trust_server_certificate
 * - Azure routing (redirect to another server during login)
 * - sp_executesql with typed parameters, attention (cancel)
 * - values formatted like pdo_sqlsrv with SQLSRV_ATTR_FETCHES_NUMERIC_TYPE
 *
 * TLS during login is wrapped in TDS packets, which PHP streams cannot do on the socket
 * itself: the TLS session runs on one end of a local socket pair and its records are
 * moved to and from the server by this class.
 */
final class TdsLink
{
    private const PACKET_SQL_BATCH = 0x01;
    private const PACKET_RPC = 0x03;
    private const PACKET_ATTENTION = 0x06;
    private const PACKET_LOGIN = 0x10;
    private const PACKET_PRELOGIN = 0x12;

    private const ENCRYPT_OFF = 0x00;
    private const ENCRYPT_ON = 0x01;
    private const ENCRYPT_NOT_SUP = 0x02;

    private const DONE_ATTN = 0x20;
    private const SP_EXECUTESQL = 10;

    /** SQL Server error number => SQLSTATE the ODBC driver (pdo_sqlsrv) reports; others 42000 */
    private const SQLSTATES = [
        207 => '42S22', 208 => '42S02', 213 => '21S01', 220 => '22003', 232 => '22003', 241 => '22007',
        242 => '22007', 245 => '22018', 515 => '23000', 547 => '23000', 1205 => '40001', 1222 => 'HYT00',
        1913 => '42S11', 2601 => '23000', 2627 => '23000', 2628 => '22001', 2705 => '42S21', 2714 => '42S01',
        3701 => '42S02', 3902 => '25000', 3903 => '25000', 4060 => '42000', 8114 => '22018', 8115 => '22003',
        8134 => '22012', 8152 => '22001', 18456 => '28000',
    ];

    /** SQL collation sort id => code page */
    private const SORT_IDS = [
        30 => 437, 31 => 437, 32 => 437, 33 => 437, 34 => 437,
        40 => 850, 41 => 850, 42 => 850, 44 => 850, 49 => 850, 55 => 850, 56 => 850, 57 => 850, 58 => 850, 59 => 850, 60 => 850, 61 => 850,
        50 => 1252, 51 => 1252, 52 => 1252, 53 => 1252, 54 => 1252, 71 => 1252, 72 => 1252, 73 => 1252, 74 => 1252, 75 => 1252,
        183 => 1252, 184 => 1252, 185 => 1252, 186 => 1252,
        80 => 1250, 81 => 1250, 82 => 1250, 83 => 1250, 84 => 1250, 85 => 1250, 86 => 1250, 87 => 1250, 88 => 1250, 89 => 1250,
        90 => 1250, 91 => 1250, 92 => 1250, 93 => 1250, 94 => 1250, 95 => 1250, 96 => 1250, 97 => 1250, 98 => 1250,
        104 => 1251, 105 => 1251, 106 => 1251, 107 => 1251, 108 => 1251,
        112 => 1253, 113 => 1253, 114 => 1253, 120 => 1253, 121 => 1253, 122 => 1253, 124 => 1253,
        128 => 1254, 129 => 1254, 130 => 1254, 136 => 1255, 137 => 1255, 138 => 1255, 144 => 1256, 145 => 1256, 146 => 1256,
        152 => 1257, 153 => 1257, 154 => 1257, 155 => 1257, 156 => 1257, 157 => 1257, 158 => 1257, 159 => 1257, 160 => 1257,
    ];

    /** Windows collation: full LCID => code page (exceptions to the language table) */
    private const LCIDS = [
        0x0404 => 950, 0x0804 => 936, 0x0C04 => 950, 0x1004 => 936, 0x1404 => 950,
        0x0C1A => 1251, 0x1C1A => 1251, 0x082C => 1251, 0x0843 => 1251,
    ];

    /** Windows collation: primary language => code page (default 1252) */
    private const LANGUAGES = [
        0x01 => 1256, 0x02 => 1251, 0x04 => 936, 0x05 => 1250, 0x08 => 1253, 0x0D => 1255, 0x0E => 1250, 0x11 => 932,
        0x12 => 949, 0x15 => 1250, 0x18 => 1250, 0x19 => 1251, 0x1A => 1250, 0x1B => 1250, 0x1C => 1250, 0x1E => 874,
        0x1F => 1254, 0x20 => 1256, 0x22 => 1251, 0x23 => 1251, 0x24 => 1250, 0x25 => 1257, 0x26 => 1257, 0x27 => 1257,
        0x29 => 1256, 0x2A => 1258, 0x2C => 1254, 0x2F => 1251, 0x3F => 1251, 0x40 => 1251, 0x43 => 1254, 0x44 => 1251,
        0x50 => 1251, 0x80 => 1256, 0x8C => 1256,
    ];

    /** @var resource */
    private $socket;
    /** @var resource|null plaintext end of the TLS socket pair */
    private $tls = null;
    /** @var resource|null ciphertext end of the TLS socket pair */
    private $pipe = null;
    /** all traffic goes through TLS (not only the login) */
    private bool $encrypted = false;
    /** TLS handshake running: records to the server go inside PRELOGIN packets */
    private bool $handshaking = false;

    private string $inbox = '';
    private string $message = '';
    private int $packetSize = 4096;
    private int $packetId = 0;
    /** default collation of the database (for nvarchar parameters) */
    private string $collation = "\x09\x04\xD0\x00\x34";
    private float $timeout = 10.0;

    private bool $ready = true;
    private bool $broken = false;
    private bool $inTransaction = false;
    private bool $attention = false;
    private bool $attentionAck = false;
    private ?array $routing = null;

    // current request
    /** @var list<array> */
    private array $columns = [];
    private int $resultSets = 0;
    private bool $firstDone = false;
    private array $rows = [];
    private ?array $error = null;
    private ?string $unsupported = null;

    private function __construct()
    {
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Whether the built-in client can log in with this config (SQL Server login, mbstring)
     */
    public static function supports(array $config): bool
    {
        return (string) ($config['username'] ?? '') !== '' && function_exists('mb_convert_encoding');
    }

    /**
     * Connect and log in (blocking, at most connect_timeout seconds per step)
     */
    public static function open(array $config): self
    {
        $link = new self();
        try {
            $link->connect($config, 0);
        } catch (\Throwable $e) {
            $link->close();
            throw $e;
        }
        return $link;
    }

    // ========================================
    // Queries
    // ========================================

    /**
     * Send a statement with @P1..@Pn parameters; read the answer with consume()
     *
     * @param list<mixed> $values int, bool, null or string
     */
    public function send(string $sql, array $values): void
    {
        if ($this->broken || !$this->ready) {
            throw new DatabaseException('The SQL Server connection is not ready for a new query.');
        }

        $this->columns = [];
        $this->resultSets = 0;
        $this->firstDone = false;
        $this->rows = [];
        $this->error = null;
        $this->unsupported = null;
        $this->ready = false;

        if ($values === []) {
            $this->writeMessage(self::PACKET_SQL_BATCH, self::allHeaders() . self::utf16le($sql));
            return;
        }

        $declarations = [];
        $parameters = '';
        foreach (array_values($values) as $i => $value) {
            [$type, $data] = $this->parameter($value);
            $name = '@P' . ($i + 1);
            $declarations[] = "{$name} {$type}";
            $parameters .= chr(strlen($name)) . self::utf16le($name) . "\0" . $data;
        }

        $this->writeMessage(
            self::PACKET_RPC,
            self::allHeaders() . "\xFF\xFF" . pack('vv', self::SP_EXECUTESQL, 0)
            . "\0\0" . $this->nvarchar($sql)[1]
            . "\0\0" . $this->nvarchar(implode(', ', $declarations))[1]
            . $parameters
        );
    }

    /**
     * Read whatever arrived without blocking; true when the answer is complete or the connection broke
     */
    public function consume(): bool
    {
        if ($this->ready || $this->broken) {
            return true;
        }

        try {
            if (!$this->pump()) {
                $this->broken = true;
            }
            $this->parsePackets();
        } catch (\Throwable $e) {
            $this->broken = true;
            $this->error ??= ['number' => 0, 'state' => '08S01', 'message' => $e->getMessage()];
        }

        return $this->ready || $this->broken;
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        $rows = $this->rows;
        $this->rows = [];
        return $rows;
    }

    /** @return array{number: int, state: string, message: string}|null */
    public function error(): ?array
    {
        return $this->error;
    }

    /**
     * Name of a column type the built-in client does not convert (sql_variant, CLR types ...)
     */
    public function unsupported(): ?string
    {
        return $this->unsupported;
    }

    public function finished(): bool
    {
        return $this->ready;
    }

    public function healthy(): bool
    {
        return !$this->broken && $this->ready && !$this->inTransaction;
    }

    /**
     * Streams to wait on for this link: the server socket and, with TLS, both ends of the local pair
     *
     * @return list<resource>
     */
    public function streams(): array
    {
        $streams = [];
        foreach ([$this->socket, $this->pipe, $this->tls] as $stream) {
            if (is_resource($stream)) {
                $streams[] = $stream;
            }
        }
        return $streams;
    }

    /**
     * Stop the running request (attention) and wait for the server's acknowledgement
     */
    public function cancel(float $wait = 5.0): bool
    {
        if ($this->ready || $this->broken) {
            return $this->healthy();
        }

        $this->attention = true;
        $this->attentionAck = false;
        try {
            $this->writeMessage(self::PACKET_ATTENTION, '');
            $deadline = microtime(true) + $wait;
            while (!$this->attentionAck && !$this->broken) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    return false;
                }
                $read = $this->streams();
                $write = $except = null;
                @stream_select($read, $write, $except, 0, (int) (min(0.2, $remaining) * 1000000));
                if (!$this->pump()) {
                    $this->broken = true;
                }
                $this->parsePackets();
            }
        } catch (\Throwable) {
            $this->broken = true;
        } finally {
            $this->attention = false;
        }

        $this->rows = [];
        $this->error = null;
        $this->ready = true;
        return $this->healthy();
    }

    public function close(): void
    {
        foreach ([$this->tls, $this->pipe, $this->socket] as $stream) {
            if (is_resource($stream)) {
                @fclose($stream);
            }
        }
        $this->tls = $this->pipe = null;
        $this->broken = true;
    }

    // ========================================
    // Login
    // ========================================

    private function connect(array $config, int $redirects): void
    {
        $host = (string) ($config['host'] ?? '') ?: 'localhost';
        $port = (int) ($config['port'] ?? 0);
        $instance = null;
        if (str_contains($host, ',')) {
            [$host, $explicitPort] = explode(',', $host, 2);
            $port = (int) $explicitPort;
        }
        if (str_contains($host, '\\')) {
            [$host, $instance] = explode('\\', $host, 2);
        }
        $this->timeout = max(1.0, (float) ($config['connect_timeout'] ?? 10));
        if ($instance !== null && $instance !== '' && ($port === 0 || $port === 1433)) {
            $port = $this->browse($host, $instance);
        }
        $port = $port ?: 1433;

        $address = 'tcp://' . (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host) . ':' . $port;
        $socket = @stream_socket_client($address, $errno, $errstr, $this->timeout);
        if ($socket === false) {
            throw new DatabaseException('Async database connection failed: ' . ($errstr !== '' ? $errstr : "error {$errno}"));
        }
        $this->socket = $socket;
        $this->broken = false;
        stream_set_read_buffer($socket, 0);
        stream_set_timeout($socket, (int) ceil($this->timeout));
        stream_set_blocking($socket, false);

        // PRELOGIN: agree on encryption
        $wantEncrypt = self::truthy($config['encrypt'] ?? false);
        $this->writeMessage(self::PACKET_PRELOGIN, self::prelogin($wantEncrypt ? self::ENCRYPT_ON : self::ENCRYPT_OFF));
        $answer = self::preloginEncryption($this->readPacket());

        if ($answer === self::ENCRYPT_NOT_SUP) {
            if ($wantEncrypt) {
                throw new DatabaseException('Async database connection failed: the server does not support encryption.');
            }
            $mode = 'none';
        } else {
            $mode = ($answer === self::ENCRYPT_OFF && !$wantEncrypt) ? 'login' : 'all';
        }

        if ($mode !== 'none') {
            // like ODBC: the certificate is checked only when all traffic is encrypted
            $this->startTls($host, $mode === 'all' && !self::truthy($config['trust_server_certificate'] ?? false));
        }
        $this->writeMessage(self::PACKET_LOGIN, $this->login7($config, $host));
        // login-only encryption: the answer is plain, but the pair stays until it arrived,
        // so the encrypted LOGIN7 still on its way through the pair is not lost
        $this->encrypted = $mode === 'all';

        $this->ready = false;
        $this->wait();
        if ($mode === 'login') {
            $this->stopTls();
        }
        if ($this->error !== null) {
            throw new DatabaseException('Async database connection failed: ' . $this->error['message']);
        }

        if ($this->routing !== null) {
            if ($redirects >= 2) {
                throw new DatabaseException('Async database connection failed: too many redirects.');
            }
            [$routeHost, $routePort] = $this->routing;
            $this->close();
            $this->reset();
            $this->connect(['host' => $routeHost, 'port' => $routePort] + $config, $redirects + 1);
        }
    }

    private function reset(): void
    {
        $this->inbox = $this->message = '';
        $this->encrypted = false;
        $this->routing = null;
        $this->error = null;
        $this->ready = true;
        $this->packetId = 0;
    }

    /**
     * Port of a named instance from the SQL Server Browser (UDP 1434)
     */
    private function browse(string $host, string $instance): int
    {
        $udp = @stream_socket_client("udp://{$host}:1434", $errno, $errstr, $this->timeout);
        if ($udp === false) {
            throw new DatabaseException("Async database connection failed: SQL Server Browser not reachable ({$errstr}).");
        }
        stream_set_timeout($udp, (int) ceil($this->timeout));
        fwrite($udp, "\x04" . $instance . "\0");
        $answer = (string) fread($udp, 65535);
        fclose($udp);

        if (($answer[0] ?? '') === "\x05" && preg_match('/;tcp;(\d+)/i', $answer, $m)) {
            return (int) $m[1];
        }
        throw new DatabaseException("Async database connection failed: instance {$instance} not found.");
    }

    private static function prelogin(int $encryption): string
    {
        $options = [
            0x00 => "\x10\x00\x00\x00\x00\x00", // client version
            0x01 => chr($encryption),
            0x02 => "\0",                       // default instance
            0x03 => pack('V', getmypid() ?: 0),
            0x04 => "\0",                       // no MARS
        ];
        $offset = count($options) * 5 + 1;
        $head = '';
        $data = '';
        foreach ($options as $token => $value) {
            $head .= chr($token) . pack('nn', $offset + strlen($data), strlen($value));
            $data .= $value;
        }
        return $head . "\xFF" . $data;
    }

    private static function preloginEncryption(string $payload): int
    {
        $pos = 0;
        while (isset($payload[$pos]) && $payload[$pos] !== "\xFF") {
            $token = ord($payload[$pos]);
            $offset = unpack('n', $payload, $pos + 1)[1];
            if ($token === 0x01) {
                return ord($payload[$offset] ?? "\x02");
            }
            $pos += 5;
        }
        return self::ENCRYPT_NOT_SUP;
    }

    private function login7(array $config, string $server): string
    {
        $fields = [
            self::utf16le(substr((string) gethostname(), 0, 128)),
            self::utf16le((string) ($config['username'] ?? '')),
            self::scramble(self::utf16le((string) ($config['password'] ?? ''))),
            self::utf16le('MikoORM'),
            self::utf16le($server),
            '',                                  // extension
            self::utf16le('MikoORM'),            // client library
            '',                                  // language
            self::utf16le((string) ($config['database'] ?? '')),
        ];

        $offset = 94;
        $pointers = '';
        $data = '';
        foreach ($fields as $value) {
            $pointers .= pack('vv', $offset + strlen($data), intdiv(strlen($value), 2));
            $data .= $value;
        }
        $end = $offset + strlen($data);
        $pointers .= str_repeat("\0", 6)     // client id
            . pack('vv', $end, 0)            // SSPI
            . pack('vv', $end, 0)            // attach db file
            . pack('vv', $end, 0)            // change password
            . pack('V', 0);                  // long SSPI

        $body = pack('VVVVV', 0x74000004, $this->packetSize, 0x07000000, getmypid() ?: 0, 0)
            . "\xE0"   // use db / init db fatal / set language warnings
            . "\x03"   // init language fatal, ODBC session defaults (ANSI options like the ODBC driver)
            . "\x00\x00"
            . pack('VV', 0, 0x0409)
            . $pointers . $data;

        return pack('V', 4 + strlen($body)) . $body;
    }

    /**
     * LOGIN7 password obfuscation: swap the nibbles of each byte, then XOR 0xA5
     */
    private static function scramble(string $password): string
    {
        $out = '';
        for ($i = 0, $n = strlen($password); $i < $n; $i++) {
            $b = ord($password[$i]);
            $out .= chr((((($b << 4) & 0xF0) | ($b >> 4)) ^ 0xA5) & 0xFF);
        }
        return $out;
    }

    // ========================================
    // TLS
    // ========================================

    private function startTls(string $host, bool $verify): void
    {
        if (!extension_loaded('openssl')) {
            throw new DatabaseException('Async database connection failed: SQL Server login encryption needs the openssl extension.');
        }

        $pair = self::cryptoPair();
        [$this->tls, $this->pipe] = $pair;
        foreach ($pair as $stream) {
            stream_set_blocking($stream, false);
            stream_set_read_buffer($stream, 0);
        }

        foreach (['verify_peer' => $verify, 'verify_peer_name' => $verify, 'peer_name' => $host, 'allow_self_signed' => !$verify] as $name => $value) {
            stream_context_set_option($this->tls, 'ssl', $name, $value);
        }

        $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
        $deadline = microtime(true) + $this->timeout;

        // during the handshake the TLS records travel inside PRELOGIN packets (pump() wraps them);
        // data crosses the local pair asynchronously, so every step waits on all three streams
        $this->handshaking = true;
        try {
            while (true) {
                $result = @stream_socket_enable_crypto($this->tls, true, $method);
                if ($result === false) {
                    $reason = error_get_last()['message'] ?? 'handshake failed';
                    throw new DatabaseException('Async database connection failed: SSL ' . preg_replace('/^stream_socket_enable_crypto\(\): /', '', $reason));
                }
                if ($result === true) {
                    return;
                }

                $this->waitReadable($deadline);
                while (($payload = $this->takePacket()) !== null) {
                    $this->feedPipe($payload);
                }
            }
        } finally {
            $this->handshaking = false;
        }
    }

    /**
     * Two connected local sockets; the first one supports TLS (stream_socket_pair() streams
     * cannot enable crypto). Both ends are checked against each other, so no other local
     * process can take the place of one.
     *
     * @return array{0: resource, 1: resource}
     */
    private static function cryptoPair(): array
    {
        // on Windows PHP waits up to 500 ms when it closes a listening socket: connect two
        // sockets to each other instead (TCP simultaneous open), the listener is the fallback
        if (PHP_OS_FAMILY === 'Windows' && ($pair = self::simultaneousPair()) !== null) {
            return $pair;
        }

        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            throw new DatabaseException("Async database connection failed: no local TLS socket ({$errstr}).");
        }

        try {
            $client = @stream_socket_client('tcp://' . stream_socket_get_name($server, false), $errno, $errstr, 5);
            if ($client === false) {
                throw new DatabaseException("Async database connection failed: no local TLS socket ({$errstr}).");
            }
            $peer = @stream_socket_accept($server, 5);
            if ($peer === false || stream_socket_get_name($peer, true) !== stream_socket_get_name($client, false)) {
                throw new DatabaseException('Async database connection failed: the local TLS socket was taken by another connection.');
            }
            return [$client, $peer];
        } finally {
            fclose($server);
        }
    }

    /**
     * @return array{0: resource, 1: resource}|null
     */
    private static function simultaneousPair(): ?array
    {
        $flags = STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $portA = self::freePort();
            $portB = self::freePort();
            if ($portA === 0 || $portB === 0 || $portA === $portB) {
                continue;
            }

            $a = @stream_socket_client("tcp://127.0.0.1:{$portB}", $errno, $errstr, 2, $flags,
                stream_context_create(['socket' => ['bindto' => "127.0.0.1:{$portA}"]]));
            $b = $a === false ? false : @stream_socket_client("tcp://127.0.0.1:{$portA}", $errno, $errstr, 2, $flags,
                stream_context_create(['socket' => ['bindto' => "127.0.0.1:{$portB}"]]));

            if ($a !== false && $b !== false) {
                $deadline = microtime(true) + 2;
                do {
                    $read = $except = null;
                    $write = [$a, $b];
                    @stream_select($read, $write, $except, 0, 20000);
                    $connected = @stream_socket_get_name($a, true) === "127.0.0.1:{$portB}"
                        && @stream_socket_get_name($b, true) === "127.0.0.1:{$portA}";
                } while (!$connected && microtime(true) < $deadline);

                if ($connected) {
                    return [$a, $b];
                }
            }
            foreach ([$a, $b] as $stream) {
                if (is_resource($stream)) {
                    @fclose($stream);
                }
            }
        }
        return null;
    }

    /**
     * A free local port (a UDP socket closes without the Windows delay)
     */
    private static function freePort(): int
    {
        $udp = @stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        if ($udp === false) {
            return 0;
        }
        $name = (string) stream_socket_get_name($udp, false);
        fclose($udp);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /**
     * Login-only encryption: back to the plain socket
     */
    private function stopTls(): void
    {
        foreach ([$this->tls, $this->pipe] as $stream) {
            if (is_resource($stream)) {
                @fclose($stream);
            }
        }
        $this->tls = $this->pipe = null;
    }

    private function drainPipe(): string
    {
        $out = '';
        while (($chunk = @fread($this->pipe, 65536)) !== false && $chunk !== '') {
            $out .= $chunk;
        }
        return $out;
    }

    /**
     * Ciphertext from the server into the pair (the TLS end decrypts it)
     */
    private function feedPipe(string $data): void
    {
        $deadline = microtime(true) + $this->timeout;
        while ($data !== '') {
            $written = @fwrite($this->pipe, $data);
            if ($written === false) {
                throw new DatabaseException('Lost the TLS socket pair.');
            }
            $data = (string) substr($data, $written);
            if ($written === 0) {
                if (microtime(true) > $deadline) {
                    throw new DatabaseException('Timeout writing to the TLS socket pair.');
                }
                if ($this->encrypted) {
                    $this->readTls(); // make room: the TLS end reads only when we read it
                }
                usleep(100);
            }
        }
    }

    /**
     * TLS records the TLS end has written: to the server (inside PRELOGIN packets during the handshake)
     */
    private function flushPipe(): void
    {
        if ($this->pipe === null) {
            return;
        }
        $records = $this->drainPipe();
        if ($records !== '') {
            $this->writeRaw($this->handshaking ? $this->packets(self::PACKET_PRELOGIN, $records) : $records);
        }
    }

    // ========================================
    // Transport
    // ========================================

    /**
     * Move whatever is ready without blocking: outgoing TLS records to the server, incoming
     * bytes into the plaintext inbox (through the pair when encrypted); false when the server closed
     */
    private function pump(): bool
    {
        $this->flushPipe();

        $open = true;
        while (true) {
            $chunk = @fread($this->socket, 65536);
            if ($chunk === false || $chunk === '') {
                $open = !($chunk === false || feof($this->socket));
                break;
            }
            if ($this->encrypted) {
                $this->feedPipe($chunk);
                $this->readTls();
            } else {
                $this->inbox .= $chunk;
            }
        }

        if ($this->encrypted) {
            $this->readTls();
        }
        return $open;
    }

    private function readTls(): void
    {
        while (($plain = @fread($this->tls, 65536)) !== false && $plain !== '') {
            $this->inbox .= $plain;
        }
    }

    private function writeMessage(int $type, string $payload): void
    {
        $data = $this->packets($type, $payload);
        if ($this->tls === null) {
            $this->writeRaw($data);
            return;
        }

        // through TLS in record-sized pieces; pump() forwards records still crossing the pair
        foreach (str_split($data, 16384) as $piece) {
            $deadline = microtime(true) + $this->timeout;
            while ($piece !== '') {
                $written = @fwrite($this->tls, $piece);
                if ($written === false) {
                    $this->broken = true;
                    throw new DatabaseException('Lost the connection to SQL Server.');
                }
                $piece = (string) substr($piece, $written);
                $this->flushPipe();
                if ($written === 0) {
                    if (microtime(true) > $deadline) {
                        throw new DatabaseException('Timeout writing to SQL Server.');
                    }
                    usleep(100);
                }
            }
        }
        $this->flushPipe();
    }

    private function writeRaw(string $data): void
    {
        stream_set_blocking($this->socket, true);
        try {
            while ($data !== '') {
                $written = @fwrite($this->socket, $data);
                if ($written === false || $written === 0) {
                    $this->broken = true;
                    throw new DatabaseException('Lost the connection to SQL Server.');
                }
                $data = (string) substr($data, $written);
            }
        } finally {
            if (is_resource($this->socket)) {
                stream_set_blocking($this->socket, false);
            }
        }
    }

    private function packets(int $type, string $payload): string
    {
        $size = $this->packetSize - 8;
        $chunks = $payload === '' ? [''] : str_split($payload, $size);
        $last = count($chunks) - 1;
        $out = '';
        foreach ($chunks as $i => $chunk) {
            $this->packetId = ($this->packetId + 1) & 0xFF;
            $out .= chr($type) . chr($i === $last ? 0x01 : 0x00) . pack('n', strlen($chunk) + 8) . "\0\0" . chr($this->packetId) . "\0" . $chunk;
        }
        return $out;
    }

    /**
     * Payload of the next packet (blocking; login only)
     */
    private function readPacket(): string
    {
        $deadline = microtime(true) + $this->timeout;
        while (($payload = $this->takePacket()) === null) {
            $this->waitReadable($deadline);
        }
        return $payload;
    }

    /**
     * Payload of the next complete packet in the inbox, or null
     */
    private function takePacket(): ?string
    {
        if (strlen($this->inbox) < 8) {
            return null;
        }
        $length = unpack('n', $this->inbox, 2)[1];
        if ($length < 8) {
            throw new DatabaseException('Invalid TDS packet from the server.');
        }
        if (strlen($this->inbox) < $length) {
            return null;
        }
        $payload = substr($this->inbox, 8, $length - 8);
        $this->inbox = (string) substr($this->inbox, $length);
        return $payload;
    }

    /**
     * Read until the current reply is complete (blocking; login only)
     */
    private function wait(): void
    {
        $deadline = microtime(true) + $this->timeout;
        while (!$this->ready) {
            $this->parsePackets();
            if ($this->ready) {
                return;
            }
            $this->waitReadable($deadline);
        }
    }

    private function waitReadable(float $deadline): void
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new DatabaseException('Async database connection failed: timeout while logging in.');
        }
        $read = $this->streams();
        $write = $except = null;
        @stream_select($read, $write, $except, 0, (int) (min(1.0, $remaining) * 1000000));
        if (!$this->pump()) {
            throw new DatabaseException('Async database connection failed: the server closed the connection.');
        }
    }

    /**
     * Collect packets into replies (the last packet of a reply has the EOM bit)
     */
    private function parsePackets(): void
    {
        while (strlen($this->inbox) >= 8) {
            $length = unpack('n', $this->inbox, 2)[1];
            if ($length < 8) {
                throw new DatabaseException('Invalid TDS packet from the server.');
            }
            if (strlen($this->inbox) < $length) {
                return;
            }
            $endOfMessage = (ord($this->inbox[1]) & 0x01) === 0x01;
            $this->message .= substr($this->inbox, 8, $length - 8);
            $this->inbox = (string) substr($this->inbox, $length);

            if ($endOfMessage) {
                $message = $this->message;
                $this->message = '';
                $this->tokens($message);
                if (!$this->attention || $this->attentionAck) {
                    $this->ready = true;
                    return;
                }
            }
        }
    }

    // ========================================
    // Tokens
    // ========================================

    private function tokens(string $m): void
    {
        $p = 0;
        $n = strlen($m);
        $columns = [];

        while ($p < $n) {
            $token = ord($m[$p++]);
            switch ($token) {
                case 0x81: // COLMETADATA
                    $columns = $this->columnMetadata($m, $p);
                    if ($columns !== []) {
                        $this->resultSets++;
                        if ($this->resultSets === 1) {
                            $this->columns = $columns;
                        }
                    }
                    break;

                case 0xD1: // ROW
                case 0xD2: // NBCROW (null bitmap)
                    $row = [];
                    $nulls = '';
                    if ($token === 0xD2) {
                        $nulls = substr($m, $p, (count($columns) + 7) >> 3);
                        $p += strlen($nulls);
                    }
                    foreach ($columns as $i => $column) {
                        $row[$column['name']] = $nulls !== '' && (ord($nulls[$i >> 3]) & (1 << ($i & 7)))
                            ? null
                            : $this->value($m, $p, $column);
                    }
                    if ($this->resultSets === 1 && !$this->firstDone) {
                        $this->rows[] = $row;
                    }
                    break;

                case 0xFD: // DONE
                case 0xFE: // DONEPROC
                case 0xFF: // DONEINPROC
                    $status = unpack('v', $m, $p)[1];
                    $p += 12;
                    if ($status & self::DONE_ATTN) {
                        $this->attentionAck = true;
                    }
                    if ($this->resultSets >= 1) {
                        $this->firstDone = true;
                    }
                    break;

                case 0xAA: // ERROR
                case 0xAB: // INFO
                    $length = unpack('v', $m, $p)[1];
                    $start = $p + 2;
                    $p = $start + $length;
                    if ($token === 0xAA && $this->error === null && !$this->firstDone) {
                        $number = unpack('V', $m, $start)[1];
                        $textLength = unpack('v', $m, $start + 6)[1];
                        $this->error = [
                            'number' => $number,
                            'state' => self::SQLSTATES[$number] ?? '42000',
                            'message' => self::utf16(substr($m, $start + 8, $textLength * 2)),
                        ];
                        $this->rows = [];
                    }
                    break;

                case 0xE3: // ENVCHANGE
                    $length = unpack('v', $m, $p)[1];
                    $this->envChange(substr($m, $p + 2, $length));
                    $p += 2 + $length;
                    break;

                case 0x79: // RETURNSTATUS
                    $p += 4;
                    break;

                case 0xAC: // RETURNVALUE (output parameters: not used, read and dropped)
                    $p += 2;
                    $p += 1 + ord($m[$p]) * 2;
                    $p += 1 + 4 + 2;
                    $this->value($m, $p, $this->typeInfo($m, $p));
                    break;

                case 0xAD: // LOGINACK
                case 0xA9: // ORDER
                case 0xA4: // TABNAME
                case 0xA5: // COLINFO
                case 0xED: // SSPI
                    $p += 2 + unpack('v', $m, $p)[1];
                    break;

                case 0xE4: // SESSIONSTATE
                    $p += 4 + unpack('V', $m, $p)[1];
                    break;

                case 0xAE: // FEATUREEXTACK
                    while (ord($m[$p]) !== 0xFF) {
                        $p += 5 + unpack('V', $m, $p + 1)[1];
                    }
                    $p++;
                    break;

                default:
                    throw new DatabaseException(sprintf('Unsupported TDS token 0x%02X.', $token));
            }
        }
    }

    private function envChange(string $data): void
    {
        $type = ord($data[0]);
        switch ($type) {
            case 4: // packet size
                $size = (int) self::utf16(substr($data, 2, ord($data[1]) * 2));
                if ($size >= 512) {
                    $this->packetSize = $size;
                }
                break;
            case 7: // collation of the database
                if (ord($data[1]) === 5) {
                    $this->collation = substr($data, 2, 5);
                }
                break;
            case 8: // begin transaction
                $this->inTransaction = true;
                break;
            case 9: // commit
            case 10: // rollback
            case 17: // transaction ended
                $this->inTransaction = false;
                break;
            case 20: // routing (Azure): value length 2, protocol 1, port 2, server name
                $port = unpack('v', $data, 4)[1];
                $length = unpack('v', $data, 6)[1];
                $this->routing = [self::utf16(substr($data, 8, $length * 2)), $port];
                break;
        }
    }

    /**
     * @return list<array{name: string, type: int, scale: int, cp: ?int, plp: bool}>
     */
    private function columnMetadata(string $m, int &$p): array
    {
        $count = unpack('v', $m, $p)[1];
        $p += 2;
        if ($count === 0xFFFF) {
            return [];
        }

        $columns = [];
        for ($i = 0; $i < $count; $i++) {
            $p += 6; // user type, flags
            $column = $this->typeInfo($m, $p);
            if (in_array($column['type'], [0x22, 0x23, 0x63], true)) { // text / ntext / image: table name
                $parts = ord($m[$p++]);
                for ($j = 0; $j < $parts; $j++) {
                    $p += 2 + unpack('v', $m, $p)[1] * 2;
                }
            }
            $nameLength = ord($m[$p++]);
            $column['name'] = self::utf16(substr($m, $p, $nameLength * 2));
            $p += $nameLength * 2;
            $columns[] = $column;
        }
        return $columns;
    }

    private function typeInfo(string $m, int &$p): array
    {
        $type = ord($m[$p++]);
        $column = ['name' => '', 'type' => $type, 'scale' => 0, 'cp' => null, 'plp' => false];

        switch ($type) {
            case 0x1F: case 0x30: case 0x32: case 0x34: case 0x38: case 0x3A:
            case 0x3B: case 0x3C: case 0x3D: case 0x3E: case 0x7A: case 0x7F:
            case 0x28: // date
                break;
            case 0x24: case 0x26: case 0x68: case 0x6D: case 0x6E: case 0x6F:
                $p++; // max length
                break;
            case 0x6A: case 0x6C: // decimal / numeric: length, precision, scale
                $p += 2;
                $column['scale'] = ord($m[$p++]);
                break;
            case 0x29: case 0x2A: case 0x2B: // time / datetime2 / datetimeoffset: scale
                $column['scale'] = ord($m[$p++]);
                break;
            case 0xA5: case 0xAD: // varbinary / binary
                $column['plp'] = unpack('v', $m, $p)[1] === 0xFFFF;
                $p += 2;
                break;
            case 0xA7: case 0xAF: case 0xE7: case 0xEF: // varchar / char / nvarchar / nchar
                $column['plp'] = unpack('v', $m, $p)[1] === 0xFFFF;
                $column['cp'] = self::codePage(substr($m, $p + 2, 5));
                $p += 7;
                break;
            case 0x22: case 0x62: // image / sql_variant
                $p += 4;
                break;
            case 0x23: case 0x63: // text / ntext
                $column['cp'] = self::codePage(substr($m, $p + 4, 5));
                $p += 9;
                break;
            case 0xF1: // xml
                if (ord($m[$p++]) === 1) {
                    $p += 1 + ord($m[$p]) * 2;
                    $p += 1 + ord($m[$p]) * 2;
                    $p += 2 + unpack('v', $m, $p)[1] * 2;
                }
                $column['plp'] = true;
                break;
            case 0xF0: // CLR type (geography, hierarchyid ...)
                $p += 2;
                $p += 1 + ord($m[$p]) * 2;
                $p += 1 + ord($m[$p]) * 2;
                $p += 1 + ord($m[$p]) * 2;
                $p += 2 + unpack('v', $m, $p)[1] * 2;
                $column['plp'] = true;
                break;
            default:
                throw new DatabaseException(sprintf('Unsupported TDS data type 0x%02X.', $type));
        }

        return $column;
    }

    /**
     * One column value, formatted like pdo_sqlsrv (UTF-8, numeric types as int / float)
     */
    private function value(string $m, int &$p, array $c): mixed
    {
        switch ($c['type']) {
            case 0x30: case 0x32: // tinyint, bit
                return ord($m[$p++]);
            case 0x34:
                $v = unpack('s', $m, $p)[1];
                $p += 2;
                return $v;
            case 0x38:
                $v = unpack('l', $m, $p)[1];
                $p += 4;
                return $v;
            case 0x7F: // bigint: a string, like pdo_sqlsrv
                $v = (string) unpack('q', $m, $p)[1];
                $p += 8;
                return $v;
            case 0x3B:
                $v = unpack('g', $m, $p)[1];
                $p += 4;
                return $v;
            case 0x3E:
                $v = unpack('e', $m, $p)[1];
                $p += 8;
                return $v;
            case 0x7A:
                $v = unpack('l', $m, $p)[1];
                $p += 4;
                return self::money($v);
            case 0x3C:
                $v = self::money(self::money8(substr($m, $p, 8)));
                $p += 8;
                return $v;
            case 0x3A:
                $v = self::smallDatetime(substr($m, $p, 4));
                $p += 4;
                return $v;
            case 0x3D:
                $v = self::datetime(substr($m, $p, 8));
                $p += 8;
                return $v;
            case 0x1F:
                return null;

            case 0x24: case 0x26: case 0x68: case 0x6D: case 0x6E: case 0x6F:
            case 0x6A: case 0x6C: case 0x28: case 0x29: case 0x2A: case 0x2B:
                $length = ord($m[$p++]);
                if ($length === 0) {
                    return null;
                }
                $raw = substr($m, $p, $length);
                $p += $length;
                return $this->byteLength($c, $raw);

            case 0xA5: case 0xA7: case 0xAD: case 0xAF: case 0xE7: case 0xEF:
                if ($c['plp']) {
                    $raw = self::plp($m, $p);
                } else {
                    $length = unpack('v', $m, $p)[1];
                    $p += 2;
                    if ($length === 0xFFFF) {
                        return null;
                    }
                    $raw = substr($m, $p, $length);
                    $p += $length;
                }
                if ($raw === null) {
                    return null;
                }
                return match ($c['type']) {
                    0xE7, 0xEF => self::utf16($raw),
                    0xA7, 0xAF => $this->ansi($raw, $c['cp']),
                    default => $raw,
                };

            case 0xF1: // xml
                $raw = self::plp($m, $p);
                return $raw === null ? null : self::utf16($raw);

            case 0xF0: // CLR type: let the main connection do it
                $raw = self::plp($m, $p);
                $this->unsupported ??= 'CLR type';
                return $raw;

            case 0x22: case 0x23: case 0x63: // image / text / ntext
                $pointer = ord($m[$p++]);
                if ($pointer === 0) {
                    return null;
                }
                $p += $pointer + 8; // text pointer, timestamp
                $length = unpack('V', $m, $p)[1];
                $raw = substr($m, $p + 4, $length);
                $p += 4 + $length;
                return match ($c['type']) {
                    0x63 => self::utf16($raw),
                    0x23 => $this->ansi($raw, $c['cp']),
                    default => $raw,
                };

            case 0x62: // sql_variant: let the main connection do it
                $length = unpack('V', $m, $p)[1];
                $p += 4 + $length;
                if ($length > 0) {
                    $this->unsupported ??= 'sql_variant';
                }
                return null;
        }

        throw new DatabaseException(sprintf('Unsupported TDS data type 0x%02X.', $c['type']));
    }

    private function byteLength(array $c, string $raw): mixed
    {
        switch ($c['type']) {
            case 0x26: // int types (bigint as a string, like pdo_sqlsrv)
                return match (strlen($raw)) {
                    1 => ord($raw),
                    2 => unpack('s', $raw)[1],
                    4 => unpack('l', $raw)[1],
                    default => (string) unpack('q', $raw)[1],
                };
            case 0x68: // bit
                return ord($raw);
            case 0x6D: // real / float
                return strlen($raw) === 4 ? unpack('g', $raw)[1] : unpack('e', $raw)[1];
            case 0x6E: // smallmoney / money
                return self::money(strlen($raw) === 4 ? unpack('l', $raw)[1] : self::money8($raw));
            case 0x6F: // smalldatetime / datetime
                return strlen($raw) === 4 ? self::smallDatetime($raw) : self::datetime($raw);
            case 0x24: // uniqueidentifier
                return sprintf('%08X-%04X-%04X-%s-%s', unpack('V', $raw)[1], unpack('v', $raw, 4)[1], unpack('v', $raw, 6)[1],
                    strtoupper(bin2hex(substr($raw, 8, 2))), strtoupper(bin2hex(substr($raw, 10, 6))));
            case 0x6A: case 0x6C: // decimal / numeric
                return self::decimal(substr($raw, 1), $c['scale'], ord($raw[0]) === 0);
            case 0x28: // date
                return self::date(self::uint($raw));
            case 0x29: // time
                return self::time(self::uint($raw), $c['scale']);
            case 0x2A: // datetime2: time, then date
                $timeLength = strlen($raw) - 3;
                return self::date(self::uint(substr($raw, $timeLength))) . ' ' . self::time(self::uint(substr($raw, 0, $timeLength)), $c['scale']);
            case 0x2B: // datetimeoffset: UTC time and date, then the offset in minutes
                $timeLength = strlen($raw) - 5;
                $units = self::uint(substr($raw, 0, $timeLength));
                $days = self::uint(substr($raw, $timeLength, 3));
                $offset = unpack('s', $raw, $timeLength + 3)[1];
                $perDay = 86400 * 10 ** $c['scale'];
                $units += $offset * 60 * 10 ** $c['scale'];
                $days += intdiv($units - ($units < 0 ? $perDay - 1 : 0), $perDay);
                $units = (($units % $perDay) + $perDay) % $perDay;
                return self::date($days) . ' ' . self::time($units, $c['scale'])
                    . sprintf(' %s%02d:%02d', $offset < 0 ? '-' : '+', intdiv(abs($offset), 60), abs($offset) % 60);
        }

        throw new DatabaseException(sprintf('Unsupported TDS data type 0x%02X.', $c['type']));
    }

    // ========================================
    // Parameters
    // ========================================

    /**
     * Declared type and TYPE_INFO + value of an RPC parameter (types like pdo_sqlsrv binds them)
     *
     * @return array{0: string, 1: string}
     */
    private function parameter(mixed $value): array
    {
        if (is_bool($value)) {
            $value = (int) $value;
        }
        if (is_int($value)) {
            return $value >= -2147483648 && $value <= 2147483647
                ? ['int', "\x26\x04\x04" . pack('V', $value & 0xFFFFFFFF)]
                : ['bigint', "\x26\x08\x08" . pack('P', $value)];
        }
        return $this->nvarchar($value === null ? null : (string) $value);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function nvarchar(?string $value): array
    {
        if ($value === null) {
            return ['nvarchar(4000)', "\xE7" . pack('v', 8000) . $this->collation . "\xFF\xFF"];
        }
        $data = self::utf16le($value);
        $length = strlen($data);
        if ($length <= 8000) {
            return ['nvarchar(4000)', "\xE7" . pack('v', 8000) . $this->collation . pack('v', $length) . $data];
        }
        return ['nvarchar(max)', "\xE7\xFF\xFF" . $this->collation . pack('P', $length) . pack('V', $length) . $data . pack('V', 0)];
    }

    private static function allHeaders(): string
    {
        // transaction descriptor header: no transaction, one outstanding request
        return pack('VVvPV', 22, 18, 2, 0, 1);
    }

    // ========================================
    // Value formatting (as the ODBC driver returns them as text)
    // ========================================

    private static function utf16le(string $utf8): string
    {
        return $utf8 === '' ? '' : mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8');
    }

    private static function utf16(string $raw): string
    {
        return $raw === '' ? '' : mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
    }

    private function ansi(string $raw, ?int $codePage): string
    {
        if ($codePage === 65001 || !preg_match('/[\x80-\xFF]/', $raw)) {
            return $raw;
        }
        $converted = false;
        if ($codePage !== null && function_exists('iconv')) {
            $converted = @iconv('CP' . $codePage, 'UTF-8', $raw);
        }
        if ($converted === false && in_array($codePage, [1251, 1252, 1254], true)) {
            $converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-' . $codePage);
        }
        if ($converted === false) {
            $this->unsupported ??= 'code page ' . ($codePage ?? 'unknown');
            return $raw;
        }
        return $converted;
    }

    private static function codePage(string $collation): ?int
    {
        if (strlen($collation) < 5) {
            return null;
        }
        $info = unpack('V', $collation)[1];
        if (($info >> 26) & 1) {
            return 65001; // _UTF8 collation
        }
        $sortId = ord($collation[4]);
        if ($sortId !== 0) {
            return self::SORT_IDS[$sortId] ?? null;
        }
        $lcid = $info & 0xFFFFF;
        return self::LCIDS[$lcid] ?? self::LANGUAGES[$lcid & 0x3FF] ?? 1252;
    }

    private static function money8(string $raw): int
    {
        return unpack('l', $raw)[1] * 4294967296 + unpack('V', $raw, 4)[1];
    }

    private static function money(int $value): string
    {
        $abs = abs($value);
        return self::odbcNumber(($value < 0 ? '-' : '') . intdiv($abs, 10000) . '.' . str_pad((string) ($abs % 10000), 4, '0', STR_PAD_LEFT));
    }

    private static function decimal(string $magnitude, int $scale, bool $negative): string
    {
        $digits = self::unsignedDecimal($magnitude);
        if ($scale > 0) {
            $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
            $digits = substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
        }
        return self::odbcNumber(($negative && trim($digits, '0.') !== '' ? '-' : '') . $digits);
    }

    /**
     * The ODBC driver writes numbers below 1 without the leading zero (".50", "-.50")
     */
    private static function odbcNumber(string $value): string
    {
        if (str_starts_with($value, '0.')) {
            return substr($value, 1);
        }
        if (str_starts_with($value, '-0.')) {
            return '-' . substr($value, 2);
        }
        return $value;
    }

    /**
     * Little-endian unsigned integer of any length as a decimal string
     */
    private static function unsignedDecimal(string $bytes): string
    {
        $bytes = rtrim($bytes, "\0");
        if ($bytes === '') {
            return '0';
        }
        if (strlen($bytes) <= 7) {
            return (string) unpack('P', str_pad($bytes, 8, "\0"))[1];
        }

        $limbs = array_reverse(array_values(unpack('V*', str_pad($bytes, (int) ceil(strlen($bytes) / 4) * 4, "\0"))));
        $out = '';
        while ($limbs !== []) {
            $remainder = 0;
            $next = [];
            foreach ($limbs as $limb) {
                $current = $remainder * 4294967296 + $limb;
                $quotient = intdiv($current, 1000000000);
                $remainder = $current % 1000000000;
                if ($next !== [] || $quotient !== 0) {
                    $next[] = $quotient;
                }
            }
            $out = str_pad((string) $remainder, 9, '0', STR_PAD_LEFT) . $out;
            $limbs = $next;
        }
        return ltrim($out, '0') ?: '0';
    }

    private static function uint(string $bytes): int
    {
        return unpack('P', str_pad($bytes, 8, "\0"))[1];
    }

    /**
     * Date from days since 0001-01-01
     */
    private static function date(int $days): string
    {
        $z = $days - 719162 + 719468; // days since 1970-01-01, shifted to 0000-03-01
        $era = intdiv($z >= 0 ? $z : $z - 146096, 146097);
        $doe = $z - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp = intdiv(5 * $doy + 2, 153);
        $day = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $month = $mp < 10 ? $mp + 3 : $mp - 9;
        $year = $yoe + $era * 400 + ($month <= 2 ? 1 : 0);
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private static function time(int $units, int $scale): string
    {
        $perSecond = 10 ** $scale;
        $seconds = intdiv($units, $perSecond);
        $text = sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60);
        return $scale > 0 ? $text . '.' . str_pad((string) ($units % $perSecond), $scale, '0', STR_PAD_LEFT) : $text;
    }

    private static function datetime(string $raw): string
    {
        $days = unpack('l', $raw)[1];
        $ms = (int) round(unpack('V', $raw, 4)[1] * 10 / 3);
        if ($ms >= 86400000) {
            $days++;
            $ms -= 86400000;
        }
        $seconds = intdiv($ms, 1000);
        return self::date($days + 693595)
            . sprintf(' %02d:%02d:%02d.%03d', intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60, $ms % 1000);
    }

    private static function smallDatetime(string $raw): string
    {
        $minutes = unpack('v', $raw, 2)[1];
        return self::date(unpack('v', $raw)[1] + 693595) . sprintf(' %02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }

    private static function plp(string $m, int &$p): ?string
    {
        $total = substr($m, $p, 8);
        $p += 8;
        if ($total === "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF") {
            return null;
        }
        $out = '';
        while (true) {
            $length = unpack('V', $m, $p)[1];
            $p += 4;
            if ($length === 0) {
                return $out;
            }
            $out .= substr($m, $p, $length);
            $p += $length;
        }
    }

    private static function truthy(mixed $value): bool
    {
        return is_string($value)
            ? !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true)
            : (bool) $value;
    }
}
