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

use Miko\Database\Drivers\PostgreSqlDriver;
use Miko\Database\Exceptions\DatabaseException;
use Miko\Database\Exceptions\QueryException;

/**
 * PostgreSQL async queries with the pgsql extension (pg_send_query_params).
 *
 * Values are sent as real parameters ($1..$n). pgsql returns every column as text,
 * so int2/int4/int8/oid become int and boolean becomes bool, the same types PDO returns.
 */
final class PgsqlDriver extends AsyncDriver
{
    private const OID_BOOL = 16;
    private const OID_BYTEA = 17;
    private const OID_INT = [20, 21, 23, 26];

    /**
     * pgsql sends parameters as C strings: values with NUL bytes run on the main connection
     */
    public function canRun(string $sql, array $bindings): bool
    {
        foreach ($bindings as $value) {
            if (is_string($value) && str_contains($value, "\0")) {
                return false;
            }
        }
        return true;
    }

    protected function openLink(): object
    {
        $config = $this->config;
        $info = [
            'host' => (string) ($config['host'] ?? 'localhost'),
            'port' => (string) ($config['port'] ?? 5432),
            'dbname' => (string) ($config['database'] ?? ''),
            'connect_timeout' => (string) max(1, (int) ($config['connect_timeout'] ?? 10)),
            'client_encoding' => (string) ($config['charset'] ?? 'UTF8'),
        ];
        if (isset($config['username']) && $config['username'] !== '') {
            $info['user'] = (string) $config['username'];
        }
        if (isset($config['password']) && $config['password'] !== '') {
            $info['password'] = (string) $config['password'];
        }
        foreach (PostgreSqlDriver::SSL_KEYS as $key) {
            if (isset($config[$key]) && $config[$key] !== '') {
                $info[$key] = (string) $config[$key];
            }
        }

        // same search_path as ConnectionFactory
        $schema = $config['schema'] ?? null;
        if (is_string($schema) && $schema !== '') {
            $schemas = array_map('trim', explode(',', $schema));
            foreach ($schemas as $name) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                    throw new DatabaseException('Invalid identifier in database config.');
                }
            }
            $info['options'] = '-c search_path=' . implode(',', $schemas);
        }

        $conninfo = '';
        foreach ($info as $key => $value) {
            $conninfo .= $key . "='" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "' ";
        }

        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });
        try {
            $link = pg_connect(trim($conninfo), PGSQL_CONNECT_FORCE_NEW);
        } finally {
            restore_error_handler();
        }

        if ($link === false) {
            $reason = $warning !== null ? preg_replace('/^pg_connect\(\): /', '', $warning) : 'unknown error';
            throw new DatabaseException('Async database connection failed: ' . $reason);
        }

        return $link;
    }

    protected function send(object $link, AsyncJob $job): void
    {
        [$sql, $params] = SqlBinder::numbered($job->sql, $job->bindings);

        if (!@pg_send_query_params($link, $sql, $params)) {
            throw QueryException::fromDriver($job->sql, $job->bindings, (string) pg_last_error($link), 7, null);
        }
    }

    protected function poll(array $links, float $timeout): array
    {
        $ready = $this->readyLinks($links);
        if ($ready !== [] || $timeout <= 0) {
            return $ready;
        }

        $sockets = [];
        foreach ($links as $link) {
            $socket = @pg_socket($link);
            if ($socket === false) {
                return $links; // broken: fetch() reports it
            }
            $sockets[] = $socket;
        }

        $read = $sockets;
        $write = $except = null;
        $seconds = (int) floor($timeout);
        if (@stream_select($read, $write, $except, $seconds, (int) round(($timeout - $seconds) * 1000000)) === false) {
            usleep(1000);
        }

        return $this->readyLinks($links);
    }

    protected function fetch(object $link, AsyncJob $job): array
    {
        $result = pg_get_result($link);
        // a query returns one result; read until the connection is free again
        while (($extra = pg_get_result($link)) !== false) {
            pg_free_result($extra);
        }

        if ($result === false) {
            throw QueryException::fromDriver($job->sql, $job->bindings, (string) pg_last_error($link), 7, null);
        }

        $status = pg_result_status($result);
        if ($status === PGSQL_TUPLES_OK) {
            $rows = $this->convert($result, pg_fetch_all($result, PGSQL_ASSOC) ?: []);
            pg_free_result($result);
            return $rows;
        }

        if ($status === PGSQL_COMMAND_OK || $status === PGSQL_EMPTY_QUERY) {
            pg_free_result($result);
            return [];
        }

        $sqlState = pg_result_error_field($result, PGSQL_DIAG_SQLSTATE);
        $message = (string) pg_result_error($result);
        pg_free_result($result);

        throw QueryException::fromDriver($job->sql, $job->bindings, $message, 7, $sqlState !== false ? (string) $sqlState : null);
    }

    protected function cancel(object $link, AsyncJob $job): void
    {
        if (!@pg_cancel_query($link) || !$this->healthy($link)) {
            throw new \RuntimeException('The cancelled query did not stop.');
        }
    }

    protected function healthy(object $link): bool
    {
        try {
            return pg_connection_status($link) === PGSQL_CONNECTION_OK;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function closeLink(object $link): void
    {
        try {
            pg_close($link);
        } catch (\Throwable) {
            // already closed
        }
    }

    // ========================================
    // Internals
    // ========================================

    /**
     * @param list<\PgSql\Connection> $links
     * @return list<\PgSql\Connection>
     */
    private function readyLinks(array $links): array
    {
        $ready = [];
        foreach ($links as $link) {
            if (!@pg_consume_input($link) || !pg_connection_busy($link)) {
                $ready[] = $link;
            }
        }
        return $ready;
    }

    /**
     * Column types like PDO: integers -> int, boolean -> bool, bytea -> binary string
     */
    private function convert(\PgSql\Result $result, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $converters = [];
        $fields = pg_num_fields($result);
        for ($i = 0; $i < $fields; $i++) {
            $oid = (int) pg_field_type_oid($result, $i);
            $name = pg_field_name($result, $i);
            if (in_array($oid, self::OID_INT, true)) {
                $converters[$name] = 'int';
            } elseif ($oid === self::OID_BOOL) {
                $converters[$name] = 'bool';
            } elseif ($oid === self::OID_BYTEA) {
                $converters[$name] = 'bytea';
            }
        }

        if ($converters === []) {
            return $rows;
        }

        foreach ($rows as &$row) {
            foreach ($converters as $name => $type) {
                $value = $row[$name] ?? null;
                if ($value === null) {
                    continue;
                }
                $row[$name] = match ($type) {
                    'int' => (int) $value,
                    'bool' => $value === 't',
                    default => pg_unescape_bytea($value),
                };
            }
        }
        unset($row);

        return $rows;
    }
}
