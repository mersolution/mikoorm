<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 */

namespace Miko\Database\Drivers;

use PDO;

/**
 * SQL Server Database Driver
 */
class SqlServerDriver implements DriverInterface
{
    public function getDsn(array $config): string
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 1433;
        $database = $config['database'] ?? '';

        $dsn = "sqlsrv:Server={$host},{$port};Database={$database}";

        // ODBC Driver 18 encrypts by default: a server with a self-signed certificate needs trust_server_certificate
        if (array_key_exists('encrypt', $config) && $config['encrypt'] !== null && $config['encrypt'] !== '') {
            $dsn .= ';Encrypt=' . (self::truthy($config['encrypt']) ? 'yes' : 'no');
        }
        if (array_key_exists('trust_server_certificate', $config) && $config['trust_server_certificate'] !== null && $config['trust_server_certificate'] !== '') {
            $dsn .= ';TrustServerCertificate=' . (self::truthy($config['trust_server_certificate']) ? '1' : '0');
        }

        return $dsn;
    }

    public function getOptions(): array
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        // int / float columns as int / float like the other drivers (decimal and money stay strings)
        if (defined('PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE')) {
            $options[constant('PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE')] = true;
        }

        return $options;
    }

    private static function truthy(mixed $value): bool
    {
        return is_string($value)
            ? !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true)
            : (bool) $value;
    }

    public function getName(): string
    {
        return 'sqlsrv';
    }

    public function getLastInsertIdQuery(?string $sequence = null): ?string
    {
        return "SELECT SCOPE_IDENTITY()";
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '[' . str_replace(']', ']]', $identifier) . ']';
    }

    public function getRandomFunction(): string
    {
        return 'NEWID()';
    }

    public function getLimitOffsetSql(int $limit, ?int $offset = null): string
    {
        // SQL Server 2012+ syntax
        if ($offset !== null) {
            return " OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
        }
        return " OFFSET 0 ROWS FETCH NEXT {$limit} ROWS ONLY";
    }
}
