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
use Miko\Database\Exceptions\QueryException;

/**
 * MySQL / MariaDB async queries with mysqli (MYSQLI_ASYNC + mysqli::poll).
 *
 * mysqli has no async prepared statements, so values are inlined as escaped
 * literals with the connection charset (real_escape_string). Result types match
 * PDO (MYSQLI_OPT_INT_AND_FLOAT_NATIVE: int / float columns as int / float).
 */
final class MysqliDriver extends AsyncDriver
{
    /** client errors after which a link is unusable: gone away, lost, out of sync, not connected */
    private const BROKEN = [2006, 2013, 2014, 2055];

    protected function openLink(): object
    {
        $config = $this->config;
        $charset = (string) ($config['charset'] ?? 'utf8mb4');

        $link = mysqli_init();
        if ($link === false) {
            throw new DatabaseException('mysqli_init() failed.');
        }

        $link->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);
        $link->options(MYSQLI_OPT_CONNECT_TIMEOUT, max(1, (int) ($config['connect_timeout'] ?? 10)));
        // escaping must know the connection charset; the init command adds the collation and session settings
        $link->options(MYSQLI_SET_CHARSET_NAME, $charset);
        $link->options(MYSQLI_INIT_COMMAND, ConnectionFactory::mysqlInitCommand($config));

        $flags = $this->sslSetup($link, (array) ($config['options'] ?? []));
        $socket = !empty($config['unix_socket']) ? (string) $config['unix_socket'] : null;

        try {
            $connected = @$link->real_connect(
                $socket !== null ? 'localhost' : (string) ($config['host'] ?? '127.0.0.1'),
                isset($config['username']) ? (string) $config['username'] : null,
                isset($config['password']) ? (string) $config['password'] : null,
                (string) ($config['database'] ?? ''),
                (int) ($config['port'] ?? 3306),
                $socket,
                $flags
            );
        } catch (\mysqli_sql_exception $e) {
            throw new DatabaseException('Async database connection failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        if (!$connected) {
            throw new DatabaseException('Async database connection failed: ' . $link->connect_error, (int) $link->connect_errno);
        }

        return $link;
    }

    protected function send(object $link, AsyncJob $job): void
    {
        /** @var \mysqli $link */
        $sql = SqlBinder::inlined($job->sql, $job->bindings, static fn(string $value): string => "'" . $link->real_escape_string($value) . "'");

        try {
            $sent = $link->query($sql, MYSQLI_ASYNC);
        } catch (\mysqli_sql_exception $e) {
            throw QueryException::fromDriver($job->sql, $job->bindings, $e->getMessage(), (int) $e->getCode(), $e->getSqlState(), $e);
        }

        if ($sent === false) {
            throw QueryException::fromDriver($job->sql, $job->bindings, $link->error, $link->errno, $link->sqlstate);
        }
    }

    protected function poll(array $links, float $timeout): array
    {
        $read = $error = $reject = $links;
        $seconds = (int) floor($timeout);
        $micro = (int) round(($timeout - $seconds) * 1000000);

        try {
            $count = \mysqli::poll($read, $error, $reject, $seconds, $micro);
        } catch (\mysqli_sql_exception) {
            return $links; // let fetch() report the error of each link
        }

        if ($count === false) {
            return $links;
        }

        $ready = [];
        foreach (array_merge($read, $error, $reject) as $link) {
            $ready[spl_object_id($link)] = $link;
        }
        return array_values($ready);
    }

    protected function fetch(object $link, AsyncJob $job): array
    {
        /** @var \mysqli $link */
        try {
            $result = $link->reap_async_query();
        } catch (\mysqli_sql_exception $e) {
            throw QueryException::fromDriver($job->sql, $job->bindings, $e->getMessage(), (int) $e->getCode(), $e->getSqlState(), $e);
        }

        if ($result === false) {
            throw QueryException::fromDriver($job->sql, $job->bindings, $link->error, $link->errno, $link->sqlstate);
        }

        if ($result === true) {
            return [];
        }

        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return $rows;
    }

    protected function cancel(object $link, AsyncJob $job): void
    {
        /** @var \mysqli $link */
        $this->connection->getPdo()->exec('KILL QUERY ' . (int) $link->thread_id);

        // the interrupted statement still has to be read before the link can run the next query
        $read = $error = $reject = [$link];
        if (!\mysqli::poll($read, $error, $reject, 5)) {
            throw new \RuntimeException('The cancelled query did not stop.');
        }

        try {
            $result = $link->reap_async_query();
            if ($result instanceof \mysqli_result) {
                $result->free();
            }
        } catch (\mysqli_sql_exception) {
            // 1317 "Query execution was interrupted" is expected
        }

        if (!$this->healthy($link)) {
            throw new \RuntimeException('Link unusable after cancel.');
        }
    }

    protected function healthy(object $link): bool
    {
        /** @var \mysqli $link */
        try {
            return !in_array($link->errno, self::BROKEN, true);
        } catch (\Throwable) {
            return false;
        }
    }

    protected function closeLink(object $link): void
    {
        try {
            /** @var \mysqli $link */
            $link->close();
        } catch (\Throwable) {
            // already closed
        }
    }

    // ========================================
    // SSL
    // ========================================

    /**
     * SSL settings from the PDO options of the connection config (also used by the built-in client):
     * key, cert, ca, capath, cipher (null when not set) and verify (false only when switched off)
     *
     * @internal
     * @return array{key: ?string, cert: ?string, ca: ?string, capath: ?string, cipher: ?string, verify: bool, enabled: bool}
     */
    public static function sslOptions(array $options): array
    {
        $attr = static function (string $name): ?int {
            foreach (['Pdo\\Mysql::ATTR_' . $name, 'PDO::MYSQL_ATTR_' . $name] as $constant) {
                if (defined($constant)) {
                    return constant($constant);
                }
            }
            return null;
        };

        $values = [];
        foreach (['key' => 'SSL_KEY', 'cert' => 'SSL_CERT', 'ca' => 'SSL_CA', 'capath' => 'SSL_CAPATH', 'cipher' => 'SSL_CIPHER'] as $name => $suffix) {
            $constant = $attr($suffix);
            $values[$name] = $constant !== null && isset($options[$constant]) && $options[$constant] !== '' ? (string) $options[$constant] : null;
        }

        $verify = $attr('SSL_VERIFY_SERVER_CERT');
        $values['verify'] = !($verify !== null && array_key_exists($verify, $options) && !$options[$verify]);
        $values['enabled'] = array_filter([$values['key'], $values['cert'], $values['ca'], $values['capath'], $values['cipher']], static fn($v) => $v !== null) !== [];

        return $values;
    }

    /**
     * Map the PDO SSL options of the connection config to mysqli
     */
    private function sslSetup(\mysqli $link, array $options): int
    {
        $ssl = self::sslOptions($options);
        if (!$ssl['enabled']) {
            return 0;
        }

        $link->ssl_set($ssl['key'], $ssl['cert'], $ssl['ca'], $ssl['capath'], $ssl['cipher']);
        return $ssl['verify'] ? MYSQLI_CLIENT_SSL : MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
    }
}
