<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 */

namespace Miko\Database\Drivers;

use PDO;

/**
 * PostgreSQL Database Driver
 */
class PostgreSqlDriver implements DriverInterface
{
    public function getDsn(array $config): string
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 5432;
        $database = $config['database'] ?? '';

        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
        // SSL settings, also used by the async connections
        foreach (self::SSL_KEYS as $key) {
            if (isset($config[$key]) && $config[$key] !== '') {
                $dsn .= ";{$key}=" . $config[$key];
            }
        }
        return $dsn;
    }

    /** libpq SSL settings accepted in the connection config */
    public const SSL_KEYS = ['sslmode', 'sslrootcert', 'sslcert', 'sslkey'];

    public function getOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
    }

    public function getName(): string
    {
        return 'pgsql';
    }

    public function getLastInsertIdQuery(?string $sequence = null): ?string
    {
        if ($sequence) {
            return "SELECT currval('{$sequence}')";
        }
        return null;
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function getRandomFunction(): string
    {
        return 'RANDOM()';
    }

    public function getLimitOffsetSql(int $limit, ?int $offset = null): string
    {
        $sql = " LIMIT {$limit}";
        if ($offset !== null) {
            $sql .= " OFFSET {$offset}";
        }
        return $sql;
    }
}
