<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\Drivers;

use PDO;

/**
 * MySQL / MariaDB Database Driver
 */
class MySqlDriver implements DriverInterface
{
    public function getDsn(array $config): string
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 3306;
        $database = $config['database'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';

        if (!empty($config['unix_socket'])) {
            return "mysql:unix_socket={$config['unix_socket']};dbname={$database};charset={$charset}";
        }

        return "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
    }

    public function getOptions(): array
    {
        // Session setup (SET NAMES, timeouts) is sent by ConnectionFactory as one INIT_COMMAND
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
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
