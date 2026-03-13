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

        return "sqlsrv:Server={$host},{$port};Database={$database}";
    }

    public function getOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
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
