<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 */

namespace Miko\Database\Drivers;

use PDO;

/**
 * SQLite Database Driver
 */
class SqliteDriver implements DriverInterface
{
    public function getDsn(array $config): string
    {
        $database = $config['database'] ?? ':memory:';
        return "sqlite:{$database}";
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
        return 'sqlite';
    }

    public function getLastInsertIdQuery(?string $sequence = null): ?string
    {
        return null; // SQLite uses PDO::lastInsertId()
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
