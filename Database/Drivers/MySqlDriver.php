<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 */

namespace Miko\Database\Drivers;

use PDO;

/**
 * MySQL Database Driver
 */
class MySqlDriver implements DriverInterface
{
    public function getDsn(array $config): string
    {
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? 3306;
        $database = $config['database'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';

        return "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
    }

    public function getOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];
    }

    public function getName(): string
    {
        return 'mysql';
    }

    public function getLastInsertIdQuery(?string $sequence = null): ?string
    {
        return null; // MySQL uses PDO::lastInsertId()
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function getRandomFunction(): string
    {
        return 'RAND()';
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
