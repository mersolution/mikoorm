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

use Miko\Database\ConnectionFactory;
use Miko\Database\Exceptions\DatabaseException;

/**
 * @internal One MySQL / MariaDB connection that speaks the client/server protocol
 * in plain PHP, so async queries need no mysqli - only sockets (and openssl for SSL).
 *
 * - TCP or Unix socket ("unix_socket"), connect_timeout
 * - SSL from the PDO options of the config (MYSQL_ATTR_SSL_CA / _CERT / _KEY / _CAPATH /
 *   _CIPHER / _VERIFY_SERVER_CERT), verified like mysqlnd
 * - mysql_native_password, caching_sha2_password (fast and full authentication, RSA when
 *   not on SSL), sha256_password, mysql_clear_password (SSL / socket only), auth switch
 * - text protocol (COM_QUERY); result types like mysqlnd with native int / float
 */
final class MyWireLink
{
    private const CLIENT_LONG_PASSWORD = 0x1;
    private const CLIENT_LONG_FLAG = 0x4;
    private const CLIENT_CONNECT_WITH_DB = 0x8;
    private const CLIENT_PROTOCOL_41 = 0x200;
    private const CLIENT_SSL = 0x800;
    private const CLIENT_TRANSACTIONS = 0x2000;
    private const CLIENT_SECURE_CONNECTION = 0x8000;
    private const CLIENT_MULTI_RESULTS = 0x20000;
    private const CLIENT_PLUGIN_AUTH = 0x80000;
    private const CLIENT_PLUGIN_AUTH_LENENC = 0x200000;

    private const STATUS_IN_TRANS = 0x1;
    private const STATUS_MORE_RESULTS = 0x8;
    private const STATUS_NO_BACKSLASH_ESCAPES = 0x200;

    private const MAX_PACKET = 0xFFFFFF;

    /** column types returned as int / float (like MYSQLI_OPT_INT_AND_FLOAT_NATIVE) */
    private const TYPE_INT = [1, 2, 3, 8, 9, 13];
    private const TYPE_FLOAT = [4, 5];
    private const TYPE_BIT = 16;
    private const FLAG_ZEROFILL = 0x40;

    /** charsets whose escaping is plain byte replacement (no multi-byte trail bytes like 0x5C) */
    private const CHARSETS = ['utf8mb4' => 45, 'utf8' => 33, 'utf8mb3' => 33, 'latin1' => 8, 'ascii' => 11];

    /** @var resource */
    private $socket;
    private string $buffer = '';
    private string $partial = '';
    private int $sequence = 0;
    private int $threadId = 0;
    private int $capabilities = 0;
    private int $status = 0;
    private bool $secure = false;
    private bool $ready = true;
    private bool $broken = false;
    private string $serverVersion = '';

    // authentication
    private string $plugin = '';
    private string $nonce = '';
    private string $password = '';

    // current result
    private string $state = 'done';
    private int $columnsLeft = 0;
    private bool $discard = false;
    /** @var list<string> */
    private array $names = [];
    /** @var list<?string> */
    private array $types = [];
    private array $rows = [];
    private ?array $error = null;

    private function __construct()
    {
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Whether the built-in client can escape values in this charset
     */
    public static function supportsCharset(?string $charset): bool
    {
        return isset(self::CHARSETS[strtolower($charset ?: 'utf8mb4')]);
    }

    /**
     * Connect, log in and run the session setup of ConnectionFactory (blocking)
     */
    public static function open(array $config): self
    {
        $link = new self();
        try {
            $link->connect($config);
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
     * Send a statement; read the answer with consume()
     */
    public function send(string $sql): void
    {
        if ($this->broken || !$this->ready) {
            throw new DatabaseException('The MySQL connection is not ready for a new query.');
        }

        $this->names = [];
        $this->types = [];
        $this->rows = [];
        $this->error = null;
        $this->discard = false;
        $this->state = 'first';
        $this->ready = false;

        $this->sequence = 0;
        $this->writePacket("\x03" . $sql); // COM_QUERY
    }

    /**
     * Read whatever arrived without blocking; true when the query finished or the connection broke
     */
    public function consume(): bool
    {
        if ($this->ready || $this->broken) {
            return true;
        }

        while (true) {
            $chunk = @fread($this->socket, 65536);
            if ($chunk === false || $chunk === '') {
                if ($chunk === false || feof($this->socket)) {
                    $this->broken = true;
                }
                break;
            }
            $this->buffer .= $chunk;
            $this->parse();
            if ($this->ready || $this->broken) {
                break;
            }
        }

        return $this->ready || $this->broken;
    }

    /** @return list<array<string, mixed>> rows of the finished query (check error() first) */
    public function rows(): array
    {
        $rows = $this->rows;
        $this->rows = [];
        return $rows;
    }

    /** @return array{code: int, state: string, message: string}|null */
    public function error(): ?array
    {
        return $this->error;
    }

    public function finished(): bool
    {
        return $this->ready;
    }

    public function healthy(): bool
    {
        return !$this->broken && $this->ready && !($this->status & self::STATUS_IN_TRANS);
    }

    public function threadId(): int
    {
        return $this->threadId;
    }

    public function serverVersion(): string
    {
        return $this->serverVersion;
    }

    /** @return resource */
    public function socket()
    {
        return $this->socket;
    }

    /**
     * Quoted string literal, escaped like mysql_real_escape_string (honours NO_BACKSLASH_ESCAPES)
     */
    public function quote(string $value): string
    {
        if ($this->status & self::STATUS_NO_BACKSLASH_ESCAPES) {
            return "'" . str_replace("'", "''", $value) . "'";
        }
        return "'" . strtr($value, [
            '\\' => '\\\\', "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', "'" => "\\'", '"' => '\\"', "\x1a" => '\\Z',
        ]) . "'";
    }

    /**
     * Wait until the running query answered (after KILL QUERY); false when the link is not reusable
     */
    public function drain(float $wait = 5.0): bool
    {
        $deadline = microtime(true) + $wait;
        while (!$this->consume()) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return false;
            }
            $read = [$this->socket];
            $write = $except = null;
            @stream_select($read, $write, $except, 0, (int) (min(0.2, $remaining) * 1000000));
        }
        $this->rows = [];
        $this->error = null;
        return $this->healthy();
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            if (!$this->broken && $this->ready) {
                @stream_set_blocking($this->socket, false);
                @fwrite($this->socket, "\x01\x00\x00\x00\x01"); // COM_QUIT
            }
            @fclose($this->socket);
        }
        $this->broken = true;
    }

    // ========================================
    // Connection setup
    // ========================================

    private function connect(array $config): void
    {
        $charset = strtolower((string) ($config['charset'] ?? '') ?: 'utf8mb4');
        if (!isset(self::CHARSETS[$charset])) {
            throw new DatabaseException("The built-in MySQL client does not support the charset {$charset}.");
        }

        $unixSocket = !empty($config['unix_socket']) ? (string) $config['unix_socket'] : null;
        $host = (string) ($config['host'] ?? '') ?: '127.0.0.1';
        $port = (int) ($config['port'] ?? 3306) ?: 3306;
        $timeout = max(1.0, (float) ($config['connect_timeout'] ?? 10));
        $address = $unixSocket !== null
            ? 'unix://' . $unixSocket
            : 'tcp://' . (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host) . ':' . $port;

        $socket = @stream_socket_client($address, $errno, $errstr, $timeout);
        if ($socket === false) {
            throw new DatabaseException('Async database connection failed: ' . ($errstr !== '' ? $errstr : "error {$errno}"));
        }
        $this->socket = $socket;
        $this->broken = false;
        $this->secure = $unixSocket !== null;
        stream_set_read_buffer($socket, 0);
        stream_set_timeout($socket, (int) ceil($timeout));

        $greeting = $this->readPacket();
        if (($greeting[0] ?? '') === "\xFF") {
            throw new DatabaseException('Async database connection failed: ' . self::errorPacket($greeting)['message']);
        }
        $serverCapabilities = $this->handshake($greeting);

        $user = (string) ($config['username'] ?? '');
        $database = (string) ($config['database'] ?? '');
        $this->password = (string) ($config['password'] ?? '');
        $ssl = MysqliDriver::sslOptions((array) ($config['options'] ?? []));

        $required = self::CLIENT_PROTOCOL_41 | self::CLIENT_SECURE_CONNECTION | self::CLIENT_PLUGIN_AUTH;
        if (($serverCapabilities & $required) !== $required) {
            throw new DatabaseException('Async database connection failed: the server is too old for the built-in MySQL client.');
        }
        $this->capabilities = $required | self::CLIENT_LONG_PASSWORD | self::CLIENT_LONG_FLAG | self::CLIENT_TRANSACTIONS
            | self::CLIENT_MULTI_RESULTS | ($serverCapabilities & self::CLIENT_PLUGIN_AUTH_LENENC)
            | ($database !== '' ? self::CLIENT_CONNECT_WITH_DB : 0);

        $charsetId = self::CHARSETS[$charset];
        if ($ssl['enabled']) {
            if (!($serverCapabilities & self::CLIENT_SSL)) {
                throw new DatabaseException('Async database connection failed: the server does not support SSL.');
            }
            $this->capabilities |= self::CLIENT_SSL;
            $this->writePacket(pack('VVC', $this->capabilities, self::MAX_PACKET, $charsetId) . str_repeat("\0", 23));
            $this->startTls($ssl, $unixSocket === null ? $host : 'localhost');
        }

        $auth = $this->scramble($this->plugin, $this->nonce);
        $this->writePacket(
            pack('VVC', $this->capabilities, self::MAX_PACKET, $charsetId) . str_repeat("\0", 23)
            . $user . "\0"
            . (($this->capabilities & self::CLIENT_PLUGIN_AUTH_LENENC) ? self::lenencInt(strlen($auth)) : chr(strlen($auth))) . $auth
            . ($database !== '' ? $database . "\0" : '')
            . $this->plugin . "\0"
        );
        $this->authenticate();
        $this->password = '';

        // same session setup as the PDO connection (SET NAMES ..., timeouts, lc_time_names)
        $this->runBlocking(ConnectionFactory::mysqlInitCommand($config));
        stream_set_blocking($socket, false);
    }

    /**
     * Initial handshake (protocol 10): thread id, nonce, auth plugin
     */
    private function handshake(string $packet): int
    {
        if (ord($packet[0]) !== 10) {
            throw new DatabaseException('Async database connection failed: unsupported protocol version ' . ord($packet[0]) . '.');
        }

        $pos = 1;
        $end = strpos($packet, "\0", $pos);
        $this->serverVersion = substr($packet, $pos, $end - $pos);
        $pos = $end + 1;
        $this->threadId = unpack('V', $packet, $pos)[1];
        $pos += 4;
        $nonce = substr($packet, $pos, 8);
        $pos += 9; // 8 bytes + filler
        $capabilities = unpack('v', $packet, $pos)[1];
        $pos += 2;

        if (strlen($packet) > $pos) {
            $this->status = unpack('v', $packet, $pos + 1)[1];
            $capabilities |= unpack('v', $packet, $pos + 3)[1] << 16;
            $nonceLength = ord($packet[$pos + 5]);
            $pos += 16; // charset 1, status 2, capabilities 2, nonce length 1, reserved 10
            $rest = max(13, $nonceLength - 8);
            $nonce .= substr($packet, $pos, $rest);
            $pos += $rest;
            $end = strpos($packet, "\0", $pos);
            $this->plugin = $end === false ? substr($packet, $pos) : substr($packet, $pos, $end - $pos);
        }

        $this->nonce = substr($nonce, 0, 20);
        if ($this->plugin === '') {
            $this->plugin = 'mysql_native_password';
        }
        return $capabilities;
    }

    private function startTls(array $ssl, string $host): void
    {
        if (!extension_loaded('openssl')) {
            throw new DatabaseException('Async database connection failed: SSL needs the openssl extension.');
        }

        // mysqlnd: verify the server certificate unless MYSQL_ATTR_SSL_VERIFY_SERVER_CERT is false
        $options = ['verify_peer' => $ssl['verify'], 'verify_peer_name' => $ssl['verify'], 'peer_name' => $host];
        if ($ssl['ca'] !== null) {
            $options['cafile'] = $ssl['ca'];
        }
        if ($ssl['capath'] !== null) {
            $options['capath'] = $ssl['capath'];
        }
        if ($ssl['cert'] !== null) {
            $options['local_cert'] = $ssl['cert'];
        }
        if ($ssl['key'] !== null) {
            $options['local_pk'] = $ssl['key'];
        }
        if ($ssl['cipher'] !== null) {
            $options['ciphers'] = $ssl['cipher'];
        }
        foreach ($options as $name => $value) {
            stream_context_set_option($this->socket, 'ssl', $name, $value);
        }

        $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
        if (@stream_socket_enable_crypto($this->socket, true, $method) !== true) {
            $reason = error_get_last()['message'] ?? 'handshake failed';
            throw new DatabaseException('Async database connection failed: SSL ' . preg_replace('/^stream_socket_enable_crypto\(\): /', '', $reason));
        }
        $this->secure = true;
    }

    /**
     * Read the server answers until the login succeeded
     */
    private function authenticate(): void
    {
        while (true) {
            $packet = $this->readPacket();
            $header = ord($packet[0] ?? "\0");

            if ($header === 0x00) {
                $this->okPacket($packet);
                return;
            }
            if ($header === 0xFF) {
                throw new DatabaseException('Async database connection failed: ' . self::errorPacket($packet)['message']);
            }

            if ($header === 0xFE) { // auth switch request
                if (strlen($packet) === 1) {
                    throw new DatabaseException('Async database connection failed: the old MySQL 4.0 password format is not supported.');
                }
                $end = strpos($packet, "\0", 1);
                $this->plugin = substr($packet, 1, $end - 1);
                $data = substr($packet, $end + 1);
                $this->nonce = str_ends_with($data, "\0") ? substr($data, 0, -1) : $data;
                $this->writePacket($this->scramble($this->plugin, $this->nonce));
                continue;
            }

            if ($header === 0x01) { // more data for the current plugin
                $data = substr($packet, 1);
                if ($this->plugin === 'caching_sha2_password' && $data === "\x03") {
                    continue; // fast authentication: OK follows
                }
                if ($this->plugin === 'caching_sha2_password' && $data === "\x04") {
                    // full authentication: password over SSL / socket, else RSA with the server key
                    $this->writePacket($this->secure ? $this->password . "\0" : "\x02");
                    continue;
                }
                if (str_starts_with($data, '-----BEGIN')) {
                    $this->writePacket($this->rsaPassword($data));
                    continue;
                }
            }

            throw new DatabaseException('Async database connection failed: unexpected answer during login.');
        }
    }

    private function scramble(string $plugin, string $nonce): string
    {
        $password = $this->password;

        switch ($plugin) {
            case 'mysql_native_password':
                if ($password === '') {
                    return '';
                }
                $hash = sha1($password, true);
                return $hash ^ sha1(substr($nonce, 0, 20) . sha1($hash, true), true);

            case 'caching_sha2_password':
                if ($password === '') {
                    return '';
                }
                $hash = hash('sha256', $password, true);
                return $hash ^ hash('sha256', hash('sha256', $hash, true) . substr($nonce, 0, 20), true);

            case 'sha256_password':
                if ($password === '') {
                    return "\0";
                }
                return $this->secure ? $password . "\0" : "\x01"; // \x01 = send me the public key

            case 'mysql_clear_password':
                if (!$this->secure) {
                    throw new DatabaseException('Async database connection failed: mysql_clear_password needs an SSL connection.');
                }
                return $password . "\0";

            default:
                // also reached after a wrong password when the account allows a second method (MariaDB "OR")
                throw new DatabaseException(
                    "Async database connection failed: login not accepted; the server asks for the {$plugin} method, "
                    . 'which the built-in client does not support (it supports mysql_native_password, caching_sha2_password, sha256_password).'
                );
        }
    }

    private function rsaPassword(string $publicKey): string
    {
        if (!extension_loaded('openssl')) {
            throw new DatabaseException('Async database connection failed: RSA password exchange needs the openssl extension (or use SSL).');
        }
        $plain = $this->password . "\0";
        $mask = str_repeat($this->nonce, intdiv(strlen($plain), max(1, strlen($this->nonce))) + 1);
        if (!openssl_public_encrypt($plain ^ substr($mask, 0, strlen($plain)), $encrypted, $publicKey, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new DatabaseException('Async database connection failed: invalid RSA public key from the server.');
        }
        return $encrypted;
    }

    /**
     * Run a statement during setup and wait for its result (blocking)
     */
    private function runBlocking(string $sql): void
    {
        $this->send($sql);
        while (!$this->ready) {
            $this->parsePacket($this->readPacket());
        }
        if ($this->error !== null) {
            throw new DatabaseException('Async database connection failed: ' . $this->error['message']);
        }
    }

    // ========================================
    // Packets
    // ========================================

    /**
     * Blocking read of one packet (setup only)
     */
    private function readPacket(): string
    {
        while (true) {
            if (strlen($this->buffer) >= 4) {
                $length = unpack('V', substr($this->buffer, 0, 3) . "\0")[1];
                if (strlen($this->buffer) >= 4 + $length) {
                    $this->sequence = (ord($this->buffer[3]) + 1) & 0xFF;
                    $payload = substr($this->buffer, 4, $length);
                    $this->buffer = (string) substr($this->buffer, 4 + $length);
                    if ($length === self::MAX_PACKET) {
                        $this->partial .= $payload;
                        continue;
                    }
                    $payload = $this->partial . $payload;
                    $this->partial = '';
                    return $payload;
                }
            }

            $chunk = fread($this->socket, 65536);
            if ($chunk === false || $chunk === '') {
                $timedOut = stream_get_meta_data($this->socket)['timed_out'] ?? false;
                throw new DatabaseException('Async database connection failed: '
                    . ($timedOut ? 'timeout while logging in.' : 'the server closed the connection.'));
            }
            $this->buffer .= $chunk;
        }
    }

    /**
     * Handle every complete packet in the buffer
     */
    private function parse(): void
    {
        $buffer = $this->buffer;
        $length = strlen($buffer);
        $pos = 0;

        while ($length - $pos >= 4) {
            $size = ord($buffer[$pos]) | (ord($buffer[$pos + 1]) << 8) | (ord($buffer[$pos + 2]) << 16);
            if ($length - $pos - 4 < $size) {
                break;
            }
            $this->sequence = (ord($buffer[$pos + 3]) + 1) & 0xFF;
            $payload = substr($buffer, $pos + 4, $size);
            $pos += 4 + $size;

            if ($size === self::MAX_PACKET) {
                $this->partial .= $payload;
                continue;
            }
            if ($this->partial !== '') {
                $payload = $this->partial . $payload;
                $this->partial = '';
            }

            $this->parsePacket($payload);
            if ($this->ready || $this->broken) {
                break;
            }
        }

        $this->buffer = $pos === 0 ? $buffer : (string) substr($buffer, $pos);
    }

    /**
     * One packet of a query answer: OK / ERR / column count, column definitions, rows, EOF
     */
    private function parsePacket(string $packet): void
    {
        $header = ord($packet[0] ?? "\0");

        switch ($this->state) {
            case 'first':
                if ($header === 0x00) {
                    $this->okPacket($packet);
                    $this->resultDone();
                    return;
                }
                if ($header === 0xFF) {
                    if (!$this->discard) {
                        $this->error = self::errorPacket($packet);
                    }
                    $this->state = 'done';
                    $this->ready = true;
                    return;
                }
                if ($header === 0xFB) {
                    // LOAD DATA LOCAL request: never allowed, answer with an empty packet
                    $this->writePacket('');
                    return;
                }
                $pos = 0;
                $this->columnsLeft = self::readLenenc($packet, $pos);
                if (!$this->discard) {
                    $this->names = [];
                    $this->types = [];
                }
                $this->state = 'columns';
                return;

            case 'columns':
                if (!$this->discard) {
                    $this->column($packet);
                }
                if (--$this->columnsLeft === 0) {
                    $this->state = 'columns-eof';
                }
                return;

            case 'columns-eof':
                $this->state = 'rows';
                return;

            case 'rows':
                if ($header === 0xFE && strlen($packet) < 9) { // EOF
                    $this->status = unpack('v', $packet, 3)[1];
                    $this->resultDone();
                    return;
                }
                if ($header === 0xFF) {
                    if (!$this->discard) {
                        $this->error = self::errorPacket($packet);
                        $this->rows = [];
                    }
                    $this->state = 'done';
                    $this->ready = true;
                    return;
                }
                if (!$this->discard) {
                    $this->rows[] = $this->row($packet);
                }
                return;
        }
    }

    /**
     * One result finished: more results (CALL) are read and dropped, the first one is kept
     */
    private function resultDone(): void
    {
        if ($this->status & self::STATUS_MORE_RESULTS) {
            $this->discard = true;
            $this->state = 'first';
            return;
        }
        $this->state = 'done';
        $this->ready = true;
    }

    private function okPacket(string $packet): void
    {
        $pos = 1;
        self::readLenenc($packet, $pos); // affected rows
        self::readLenenc($packet, $pos); // last insert id
        if (strlen($packet) >= $pos + 2) {
            $this->status = unpack('v', $packet, $pos)[1];
        }
    }

    private function column(string $packet): void
    {
        $pos = 0;
        for ($i = 0; $i < 4; $i++) { // catalog, schema, table, org_table
            $pos += self::readLenenc($packet, $pos);
        }
        $nameLength = self::readLenenc($packet, $pos);
        $name = substr($packet, $pos, $nameLength);
        $pos += $nameLength;
        $pos += self::readLenenc($packet, $pos); // org_name
        self::readLenenc($packet, $pos); // length of the fixed fields (0x0c)
        $pos += 6; // charset 2, column length 4
        $type = ord($packet[$pos]);
        $flags = unpack('v', $packet, $pos + 1)[1];

        $this->names[] = $name;
        $this->types[] = match (true) {
            in_array($type, self::TYPE_INT, true) => ($flags & self::FLAG_ZEROFILL) ? null : 'int',
            in_array($type, self::TYPE_FLOAT, true) => 'float',
            $type === self::TYPE_BIT => 'bit',
            default => null,
        };
    }

    private function row(string $packet): array
    {
        $row = [];
        $pos = 0;
        $names = $this->names;
        $types = $this->types;

        foreach ($names as $i => $name) {
            $first = ord($packet[$pos]);
            if ($first === 0xFB) {
                $row[$name] = null;
                $pos++;
                continue;
            }
            if ($first < 0xFB) {
                $length = $first;
                $pos++;
            } else {
                $length = self::readLenenc($packet, $pos);
            }
            $value = substr($packet, $pos, $length);
            $pos += $length;

            $type = $types[$i];
            if ($type === null) {
                $row[$name] = $value;
            } elseif ($type === 'int') {
                // unsigned BIGINT above PHP_INT_MAX stays a string, like mysqlnd
                $int = (int) $value;
                $row[$name] = (string) $int === $value ? $int : $value;
            } elseif ($type === 'float') {
                $row[$name] = (float) $value;
            } else {
                $bits = 0;
                for ($b = 0, $n = strlen($value); $b < $n; $b++) {
                    $bits = ($bits << 8) | ord($value[$b]);
                }
                $row[$name] = $bits;
            }
        }

        return $row;
    }

    private function writePacket(string $payload): void
    {
        $data = '';
        do {
            $chunk = substr($payload, 0, self::MAX_PACKET);
            $payload = (string) substr($payload, self::MAX_PACKET);
            $data .= substr(pack('V', strlen($chunk)), 0, 3) . chr($this->sequence);
            $data .= $chunk;
            $this->sequence = ($this->sequence + 1) & 0xFF;
        } while (strlen($chunk) === self::MAX_PACKET);

        $this->write($data);
    }

    private function write(string $data): void
    {
        $blocking = (bool) (stream_get_meta_data($this->socket)['blocked'] ?? true);
        if (!$blocking) {
            stream_set_blocking($this->socket, true);
        }

        try {
            while ($data !== '') {
                $written = @fwrite($this->socket, $data);
                if ($written === false || $written === 0) {
                    $this->broken = true;
                    throw new DatabaseException('Lost the connection to the MySQL server.');
                }
                $data = (string) substr($data, $written);
            }
        } finally {
            if (!$blocking && is_resource($this->socket)) {
                stream_set_blocking($this->socket, false);
            }
        }
    }

    /**
     * Length-encoded integer at $pos (moves $pos past it)
     */
    private static function readLenenc(string $data, int &$pos): int
    {
        $first = ord($data[$pos] ?? "\0");
        $pos++;
        if ($first < 0xFB) {
            return $first;
        }
        if ($first === 0xFC) {
            $value = unpack('v', $data, $pos)[1];
            $pos += 2;
            return $value;
        }
        if ($first === 0xFD) {
            $value = unpack('V', substr($data, $pos, 3) . "\0")[1];
            $pos += 3;
            return $value;
        }
        if ($first === 0xFE) {
            $value = unpack('P', $data, $pos)[1];
            $pos += 8;
            return $value;
        }
        return 0; // 0xFB (NULL) / 0xFF
    }

    private static function lenencInt(int $value): string
    {
        return match (true) {
            $value < 0xFB => chr($value),
            $value <= 0xFFFF => "\xFC" . pack('v', $value),
            $value <= 0xFFFFFF => "\xFD" . substr(pack('V', $value), 0, 3),
            default => "\xFE" . pack('P', $value),
        };
    }

    /**
     * @return array{code: int, state: string, message: string}
     */
    private static function errorPacket(string $packet): array
    {
        $code = unpack('v', $packet, 1)[1];
        if (($packet[3] ?? '') === '#') {
            return ['code' => $code, 'state' => substr($packet, 4, 5), 'message' => substr($packet, 9)];
        }
        return ['code' => $code, 'state' => 'HY000', 'message' => substr($packet, 3)];
    }
}
