<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database;

use PDO;

/**
 * DB Facade - Static database access
 */
class DB
{
    private static ?Connection $connection = null;

    private function __construct() {}

    /**
     * Get Connection instance
     *
     * @return Connection
     */
    public static function connection(): Connection
    {
        if (self::$connection === null)
        {
            $config = require __DIR__ . '/../Config/Database.php';
            $default = $config['default'] ?? 'mysql';
            $connConfig = $config['connections'][$default];

            $charset = $connConfig['charset'] ?? 'utf8mb4';

            $dsn = sprintf(
                '%s:host=%s;port=%s;dbname=%s;charset=%s',
                $connConfig['driver'] ?? 'mysql',
                $connConfig['host'] ?? '127.0.0.1',
                $connConfig['port'] ?? 3306,
                $connConfig['database'] ?? '',
                $charset
            );

            $pdo = new PDO(
                $dsn,
                $connConfig['username'] ?? '',
                $connConfig['password'] ?? '',
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );

            // SET NAMES + COLLATE (charset DSN'de var ama collation için gerekli)
            $collation = $connConfig['collation'] ?? 'utf8mb4_unicode_ci';
            $pdo->exec("SET NAMES '{$charset}' COLLATE '{$collation}', lc_time_names = 'tr_TR'");

            self::$connection = new Connection($pdo, $connConfig);
        }

        return self::$connection;
    }

    public static function query(string $sql, array $params = []): array
    {
        return self::connection()->execute($sql, $params)->all();
    }

    public static function execute(string $sql, array $params = []): int
    {
        return self::connection()->execute($sql, $params)->count();
    }

    public static function first(string $sql, array $params = []): ?array
    {
        return self::connection()->execute($sql, $params)->first();
    }

    public static function scalar(string $sql, array $params = []): mixed
    {
        $row = self::first($sql, $params);
        if ($row === null) {
            return null;
        }
        return reset($row);
    }

    public static function lastInsertId(): string|int
    {
        return self::connection()->lastInsertId();
    }

    public static function beginTransaction(): void
    {
        self::connection()->beginTransaction();
    }

    public static function commit(): void
    {
        self::connection()->commit();
    }

    public static function rollback(): void
    {
        self::connection()->rollback();
    }

    private function __clone() {}
    public function __wakeup() {}
}
