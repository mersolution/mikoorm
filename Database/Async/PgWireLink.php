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
 * @internal One PostgreSQL connection that speaks the wire protocol (3.0) in plain PHP,
 * so async queries need no pgsql extension - only sockets (and openssl for SSL).
 *
 * - TCP or Unix socket ("host" starting with "/"), connect_timeout
 * - sslmode disable / allow / prefer (default) / require / verify-ca / verify-full,
 *   sslrootcert, sslcert, sslkey
 * - SCRAM-SHA-256 (server signature checked), MD5 and password authentication
 * - extended query protocol with text parameters (like pg_send_query_params)
 * - cancel requests
 */
final class PgWireLink
{
    private const PROTOCOL = 196608; // 3.0
    private const SSL_REQUEST = 80877103;
    private const CANCEL_REQUEST = 80877102;
    private const NULL_LENGTH = 0xFFFFFFFF;

    private const OID_BOOL = 16;
    private const OID_BYTEA = 17;
    private const OID_INT = [20, 21, 23, 26];

    /** @var resource */
    private $socket;
    private string $address = '';
    private float $timeout = 10.0;

    private string $buffer = '';
    private int $pid = 0;
    private string $secret = '';

    /** transaction status of the last ReadyForQuery: I idle, T in transaction, E failed transaction */
    private string $status = 'I';
    private bool $ready = true;
    private bool $broken = false;

    /** @var array{nonce: string, first: string, password: string, signature: ?string, verified: bool}|null */
    private ?array $scram = null;

    /** @var list<string> */
    private array $names = [];
    /** @var list<?string> */
    private array $types = [];
    private array $rows = [];
    private ?array $error = null;

    /** server parameters (server_version, client_encoding, ...) */
    private array $parameters = [];

    private function __construct()
    {
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Connect and log in (blocking, at most connect_timeout seconds per step)
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
     * Send a statement with $1..$n text parameters; read the answer with consume()
     *
     * @param list<?string> $params
     */
    public function send(string $sql, array $params): void
    {
        if ($this->broken || !$this->ready) {
            throw new DatabaseException('The PostgreSQL connection is not ready for a new query.');
        }
        if (count($params) > 65535) {
            throw new DatabaseException('PostgreSQL accepts at most 65535 parameters per query.');
        }

        $bind = "\0\0" . pack('nn', 0, count($params));
        foreach ($params as $value) {
            $bind .= $value === null ? pack('N', self::NULL_LENGTH) : pack('N', strlen($value)) . $value;
        }
        $bind .= pack('n', 0);

        $this->names = [];
        $this->types = [];
        $this->rows = [];
        $this->error = null;
        $this->ready = false;

        // Parse, Bind, Describe portal, Execute, Sync in one round trip
        $this->write(
            self::message('P', "\0" . $sql . "\0" . pack('n', 0))
            . self::message('B', $bind)
            . self::message('D', "P\0")
            . self::message('E', "\0" . pack('N', 0))
            . self::message('S', '')
        );
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

    /**
     * Rows of the finished query (check error() first)
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $rows = $this->rows;
        $this->rows = [];
        return $rows;
    }

    /**
     * Error fields of the finished query (C = SQLSTATE, M = message, ...), or null
     */
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
        return !$this->broken && $this->ready && $this->status === 'I';
    }

    /** @return resource */
    public function socket()
    {
        return $this->socket;
    }

    public function parameter(string $name): ?string
    {
        return $this->parameters[$name] ?? null;
    }

    /**
     * Stop the running query (CancelRequest on a second socket) and wait until the
     * connection is idle again; false when it is not reusable
     */
    public function cancel(float $wait = 5.0): bool
    {
        if ($this->ready || $this->broken) {
            return $this->healthy();
        }

        $socket = @stream_socket_client($this->address, $errno, $errstr, $this->timeout);
        if ($socket === false) {
            return false;
        }
        stream_set_timeout($socket, (int) ceil($wait));
        $body = pack('NN', self::CANCEL_REQUEST, $this->pid) . $this->secret;
        @fwrite($socket, pack('N', strlen($body) + 4) . $body);
        @fread($socket, 1); // the server closes the socket once the request was handled
        fclose($socket);

        // the interrupted statement still answers (57014 + ReadyForQuery)
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
            if (!$this->broken) {
                @stream_set_blocking($this->socket, false);
                @fwrite($this->socket, self::message('X', '')); // Terminate
            }
            @fclose($this->socket);
        }
        $this->broken = true;
    }

    /**
     * Message text in the libpq style: "ERROR:  ..." plus DETAIL / HINT lines
     */
    public static function errorText(array $fields): string
    {
        $text = ($fields['S'] ?? 'ERROR') . ':  ' . ($fields['M'] ?? 'unknown error');
        if (isset($fields['D'])) {
            $text .= "\nDETAIL:  " . $fields['D'];
        }
        if (isset($fields['H'])) {
            $text .= "\nHINT:  " . $fields['H'];
        }
        return $text;
    }

    // ========================================
    // Connection setup
    // ========================================

    private function connect(array $config): void
    {
        $host = (string) ($config['host'] ?? '');
        $host = $host !== '' ? $host : 'localhost';
        $port = (int) ($config['port'] ?? 5432) ?: 5432;
        $this->timeout = max(1.0, (float) ($config['connect_timeout'] ?? 10));

        $unix = str_starts_with($host, '/');
        $this->address = $unix
            ? 'unix://' . rtrim($host, '/') . '/.s.PGSQL.' . $port
            : 'tcp://' . (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host) . ':' . $port;

        $socket = @stream_socket_client($this->address, $errno, $errstr, $this->timeout);
        if ($socket === false) {
            throw new DatabaseException('Async database connection failed: ' . ($errstr !== '' ? $errstr : "error {$errno}"));
        }
        $this->socket = $socket;
        $this->broken = false;
        // no PHP read buffer: stream_select() must see every byte that is not read yet
        stream_set_read_buffer($socket, 0);
        stream_set_timeout($socket, (int) ceil($this->timeout));

        if (!$unix) {
            $this->startTls($config, $host);
        }
        $this->startup($config);

        stream_set_blocking($socket, false);
    }

    private function startTls(array $config, string $host): void
    {
        $mode = strtolower((string) ($config['sslmode'] ?? (getenv('PGSSLMODE') ?: 'prefer')));
        if (!in_array($mode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
            throw new DatabaseException('Invalid sslmode in database config.');
        }
        if ($mode === 'disable' || $mode === 'allow') {
            return;
        }

        $this->write(pack('NN', 8, self::SSL_REQUEST));
        $answer = fread($this->socket, 1);
        if ($answer === 'N') {
            if ($mode === 'prefer') {
                return;
            }
            throw new DatabaseException("Async database connection failed: the server does not support SSL (sslmode={$mode}).");
        }
        if ($answer !== 'S') {
            throw new DatabaseException('Async database connection failed: unexpected answer to the SSL request.');
        }
        if (!extension_loaded('openssl')) {
            throw new DatabaseException('Async database connection failed: SSL needs the openssl extension.');
        }

        $verify = $mode === 'verify-ca' || $mode === 'verify-full';
        $options = [
            'verify_peer' => $verify,
            'verify_peer_name' => $mode === 'verify-full',
            'peer_name' => $host,
        ];
        $rootCert = (string) ($config['sslrootcert'] ?? '');
        if ($rootCert !== '' && $rootCert !== 'system') {
            $options['cafile'] = $rootCert;
        }
        if (!empty($config['sslcert'])) {
            $options['local_cert'] = (string) $config['sslcert'];
        }
        if (!empty($config['sslkey'])) {
            $options['local_pk'] = (string) $config['sslkey'];
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
    }

    private function startup(array $config): void
    {
        $user = (string) ($config['username'] ?? '');
        if ($user === '') {
            $user = (string) (getenv('PGUSER') ?: get_current_user());
        }
        $password = isset($config['password']) && $config['password'] !== ''
            ? (string) $config['password']
            : (string) (getenv('PGPASSWORD') ?: '');

        $params = ['user' => $user];
        $database = (string) ($config['database'] ?? '');
        if ($database !== '') {
            $params['database'] = $database;
        }
        $charset = (string) ($config['charset'] ?? '');
        $params['client_encoding'] = $charset !== '' ? $charset : 'UTF8';

        // same search_path as ConnectionFactory
        $schema = $config['schema'] ?? null;
        if (is_string($schema) && $schema !== '') {
            $schemas = array_map('trim', explode(',', $schema));
            foreach ($schemas as $name) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                    throw new DatabaseException('Invalid identifier in database config.');
                }
            }
            $params['options'] = '-c search_path=' . implode(',', $schemas);
        }

        $body = pack('N', self::PROTOCOL);
        foreach ($params as $name => $value) {
            if (str_contains($value, "\0")) {
                throw new DatabaseException('Invalid value in database config.');
            }
            $body .= $name . "\0" . $value . "\0";
        }
        $body .= "\0";
        $this->write(pack('N', strlen($body) + 4) . $body);

        while (true) {
            [$type, $data] = $this->readMessage();
            switch ($type) {
                case 'R':
                    $this->authenticate($data, $user, $password);
                    break;
                case 'S':
                    $this->serverParameter($data);
                    break;
                case 'K':
                    $this->pid = unpack('N', $data)[1];
                    $this->secret = substr($data, 4);
                    break;
                case 'Z':
                    $this->status = $data;
                    $this->ready = true;
                    $this->scram = null;
                    return;
                case 'E':
                    throw new DatabaseException('Async database connection failed: ' . self::errorText(self::errorFields($data)));
                case 'N': // notice
                case 'v': // NegotiateProtocolVersion: nothing optional was asked for
                    break;
                default:
                    throw new DatabaseException("Async database connection failed: unexpected message '{$type}' during login.");
            }
        }
    }

    private function authenticate(string $data, string $user, string $password): void
    {
        $code = unpack('N', $data)[1];

        switch ($code) {
            case 0: // AuthenticationOk
                if ($this->scram !== null && !$this->scram['verified']) {
                    throw new DatabaseException('Async database connection failed: the server ended SCRAM authentication without proving it knows the password.');
                }
                return;

            case 3: // cleartext password
                $this->writeMessage('p', $this->requirePassword($password) . "\0");
                return;

            case 5: // MD5
                $salt = substr($data, 4, 4);
                $this->writeMessage('p', 'md5' . md5(md5($this->requirePassword($password) . $user) . $salt) . "\0");
                return;

            case 10: // SASL: mechanism list
                $mechanisms = explode("\0", rtrim(substr($data, 4), "\0"));
                if (!in_array('SCRAM-SHA-256', $mechanisms, true)) {
                    throw new DatabaseException('Async database connection failed: unsupported SASL mechanism (' . implode(', ', $mechanisms) . ').');
                }
                $nonce = base64_encode(random_bytes(18));
                $this->scram = [
                    'nonce' => $nonce,
                    'first' => 'n=,r=' . $nonce,
                    'password' => $this->requirePassword($password),
                    'signature' => null,
                    'verified' => false,
                ];
                // no channel binding ("n,,"); the user name is taken from the startup message
                $payload = 'n,,' . $this->scram['first'];
                $this->writeMessage('p', "SCRAM-SHA-256\0" . pack('N', strlen($payload)) . $payload);
                return;

            case 11: // SASL continue: server-first-message
                $this->scramProof(substr($data, 4));
                return;

            case 12: // SASL final: server signature
                $this->scramVerify(substr($data, 4));
                return;

            default:
                throw new DatabaseException(
                    "Async database connection failed: authentication method {$code} is not supported "
                    . '(the built-in client supports scram-sha-256, md5 and password; install the pgsql extension for others).'
                );
        }
    }

    private function scramProof(string $serverFirst): void
    {
        if ($this->scram === null) {
            throw new DatabaseException('Async database connection failed: unexpected SCRAM message.');
        }

        $attributes = self::scramAttributes($serverFirst);
        $nonce = $attributes['r'] ?? '';
        $salt = base64_decode($attributes['s'] ?? '', true);
        $iterations = (int) ($attributes['i'] ?? 0);

        if (!str_starts_with($nonce, $this->scram['nonce']) || strlen($nonce) <= strlen($this->scram['nonce'])
            || $salt === false || $salt === '' || $iterations < 1) {
            throw new DatabaseException('Async database connection failed: invalid SCRAM challenge from the server.');
        }

        $salted = hash_pbkdf2('sha256', self::saslPrep($this->scram['password']), $salt, $iterations, 32, true);
        $clientKey = hash_hmac('sha256', 'Client Key', $salted, true);
        $storedKey = hash('sha256', $clientKey, true);
        $final = 'c=biws,r=' . $nonce; // biws = base64("n,,")
        $message = $this->scram['first'] . ',' . $serverFirst . ',' . $final;
        $proof = $clientKey ^ hash_hmac('sha256', $message, $storedKey, true);

        $this->scram['signature'] = hash_hmac('sha256', $message, hash_hmac('sha256', 'Server Key', $salted, true), true);
        $this->scram['password'] = '';

        $this->writeMessage('p', $final . ',p=' . base64_encode($proof));
    }

    private function scramVerify(string $serverFinal): void
    {
        $attributes = self::scramAttributes($serverFinal);
        if (isset($attributes['e'])) {
            throw new DatabaseException('Async database connection failed: SCRAM error ' . $attributes['e']);
        }

        $signature = base64_decode($attributes['v'] ?? '', true);
        if ($this->scram === null || $this->scram['signature'] === null || $signature === false
            || !hash_equals($this->scram['signature'], $signature)) {
            throw new DatabaseException('Async database connection failed: invalid SCRAM server signature.');
        }
        $this->scram['verified'] = true;
    }

    private function requirePassword(string $password): string
    {
        if ($password === '') {
            throw new DatabaseException('Async database connection failed: the server asks for a password but none is configured.');
        }
        return $password;
    }

    // ========================================
    // Protocol
    // ========================================

    /**
     * Blocking read of one message (login only)
     *
     * @return array{0: string, 1: string}
     */
    private function readMessage(): array
    {
        while (true) {
            if (strlen($this->buffer) >= 5) {
                $size = unpack('N', $this->buffer, 1)[1];
                if ($size < 4) {
                    throw new DatabaseException('Async database connection failed: invalid message from the server.');
                }
                if (strlen($this->buffer) > $size) {
                    $message = [$this->buffer[0], substr($this->buffer, 5, $size - 4)];
                    $this->buffer = (string) substr($this->buffer, $size + 1);
                    return $message;
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
     * Handle every complete message in the buffer
     */
    private function parse(): void
    {
        $buffer = $this->buffer;
        $length = strlen($buffer);
        $pos = 0;

        while ($length - $pos >= 5) {
            $size = unpack('N', $buffer, $pos + 1)[1];
            if ($size < 4) {
                $this->broken = true;
                break;
            }
            if ($length - $pos - 1 < $size) {
                break;
            }

            $type = $buffer[$pos];
            $start = $pos + 5;
            $pos += $size + 1;

            // DataRow is read in place (no copy of the message): it is most of the traffic
            if ($type === 'D') {
                if ($this->error === null) {
                    $this->rows[] = $this->row($buffer, $start);
                }
                continue;
            }

            $data = substr($buffer, $start, $size - 4);
            switch ($type) {
                case 'T':
                    $this->describe($data);
                    break;
                case 'E':
                    $this->error ??= self::errorFields($data);
                    break;
                case 'Z':
                    $this->status = $data;
                    $this->ready = true;
                    break;
                case 'S':
                    $this->serverParameter($data);
                    break;
                default:
                    // 1 2 3 C I n s: progress only; N notice, A notification: ignored
                    break;
            }
        }

        $this->buffer = $pos === 0 ? $buffer : (string) substr($buffer, $pos);
    }

    /**
     * RowDescription: column names and the type conversions PDO does (int, bool, bytea)
     */
    private function describe(string $data): void
    {
        $count = unpack('n', $data)[1];
        $pos = 2;
        $this->names = [];
        $this->types = [];

        for ($i = 0; $i < $count; $i++) {
            $end = strpos($data, "\0", $pos);
            if ($end === false) {
                $this->broken = true;
                return;
            }
            $this->names[] = substr($data, $pos, $end - $pos);
            // after the name: table oid (4), column number (2), type oid (4), size (2), modifier (4), format (2)
            $oid = unpack('N', $data, $end + 7)[1];
            $this->types[] = match (true) {
                in_array($oid, self::OID_INT, true) => 'int',
                $oid === self::OID_BOOL => 'bool',
                $oid === self::OID_BYTEA => 'bytea',
                default => null,
            };
            $pos = $end + 19;
        }
    }

    /**
     * DataRow starting at $pos of the buffer
     */
    private function row(string $buffer, int $pos): array
    {
        $count = unpack('n', $buffer, $pos)[1];
        $pos += 2;
        $names = $this->names;
        $types = $this->types;
        $row = [];

        for ($i = 0; $i < $count; $i++) {
            $size = unpack('N', $buffer, $pos)[1];
            $pos += 4;
            $name = $names[$i] ?? (string) $i;

            if ($size === self::NULL_LENGTH) {
                $row[$name] = null;
                continue;
            }

            $value = substr($buffer, $pos, $size);
            $pos += $size;
            $type = $types[$i] ?? null;
            $row[$name] = $type === null ? $value : match ($type) {
                'int' => (int) $value,
                'bool' => $value === 't',
                default => self::bytea($value),
            };
        }

        return $row;
    }

    private function serverParameter(string $data): void
    {
        $parts = explode("\0", $data);
        if (count($parts) >= 2) {
            $this->parameters[$parts[0]] = $parts[1];
        }
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
                    throw new DatabaseException('Lost the connection to the PostgreSQL server.');
                }
                $data = (string) substr($data, $written);
            }
        } finally {
            if (!$blocking && is_resource($this->socket)) {
                stream_set_blocking($this->socket, false);
            }
        }
    }

    private function writeMessage(string $type, string $body): void
    {
        $this->write(self::message($type, $body));
    }

    private static function message(string $type, string $body): string
    {
        return $type . pack('N', strlen($body) + 4) . $body;
    }

    private static function errorFields(string $data): array
    {
        $fields = [];
        foreach (explode("\0", $data) as $part) {
            if ($part !== '') {
                $fields[$part[0]] = substr($part, 1);
            }
        }
        return $fields;
    }

    private static function scramAttributes(string $message): array
    {
        $attributes = [];
        foreach (explode(',', $message) as $part) {
            if (strlen($part) >= 2 && $part[1] === '=') {
                $attributes[$part[0]] = substr($part, 2);
            }
        }
        return $attributes;
    }

    /**
     * SASLprep (RFC 4013) for non-ASCII passwords, as the server does when it stores the verifier
     */
    private static function saslPrep(string $password): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $password) || !class_exists(\Normalizer::class)) {
            return $password;
        }

        $mapped = preg_replace(
            [
                '/[\x{00A0}\x{1680}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]/u',
                '/[\x{00AD}\x{034F}\x{1806}\x{180B}-\x{180D}\x{200C}\x{200D}\x{2060}\x{FE00}-\x{FE0F}\x{FEFF}]/u',
            ],
            [' ', ''],
            $password
        );
        if ($mapped === null) {
            return $password; // not UTF-8: the server uses the raw bytes too
        }

        $normalized = \Normalizer::normalize($mapped, \Normalizer::FORM_KC);
        return is_string($normalized) && $normalized !== '' ? $normalized : $password;
    }

    /**
     * bytea text output: hex ("\x4142") or the old escape format
     */
    private static function bytea(string $value): string
    {
        if (str_starts_with($value, '\\x')) {
            return (string) hex2bin(substr($value, 2));
        }
        return (string) preg_replace_callback(
            '/\\\\(\\\\|[0-7]{3})/',
            static fn(array $m): string => $m[1] === '\\' ? '\\' : chr(octdec($m[1])),
            $value
        );
    }
}
