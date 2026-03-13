<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Core\Database;

use Miko\Core\Config;
use Miko\Database\Connection;
use PDO;

/**
 * Database Config Helper - Creates PDO connections from config
 */
class DatabaseConfig
{
    /**
     * Create PDO connection from config
     */
    public static function createConnection(?string $name = null): Connection
    {
        $name = $name ?? Config::get('database.default', 'mysql');
        $config = Config::get("database.connections.{$name}");

        if (!$config) {
            throw new \Exception("Database connection [{$name}] not configured.");
        }

        $pdo = self::createPdo($config);
        
        return new Connection($pdo, $config);
    }

    /**
     * Create PDO instance
     */
    private static function createPdo(array $config): PDO
    {
        $driver = $config['driver'];

        return match($driver) {
            'mysql' => self::createMySqlPdo($config),
            'sqlite' => self::createSqlitePdo($config),
            'sqlsrv' => self::createSqlServerPdo($config),
            'pgsql' => self::createPostgreSqlPdo($config),
            default => throw new \Exception("Unsupported database driver: {$driver}")
        };
    }

    /**
     * Create MySQL PDO
     */
    private static function createMySqlPdo(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'] ?? 3306,
            $config['database'],
            $config['charset'] ?? 'utf8mb4'
        );

        return new PDO(
            $dsn,
            $config['username'],
            $config['password'],
            $config['options'] ?? []
        );
    }

    /**
     * Create SQLite PDO
     */
    private static function createSqlitePdo(array $config): PDO
    {
        $dsn = 'sqlite:' . $config['database'];

        return new PDO($dsn, null, null, $config['options'] ?? []);
    }

    /**
     * Create SQL Server PDO
     */
    private static function createSqlServerPdo(array $config): PDO
    {
        $dsn = sprintf(
            'sqlsrv:Server=%s,%s;Database=%s',
            $config['host'],
            $config['port'] ?? 1433,
            $config['database']
        );

        return new PDO(
            $dsn,
            $config['username'],
            $config['password'],
            $config['options'] ?? []
        );
    }

    /**
     * Create PostgreSQL PDO
     */
    private static function createPostgreSqlPdo(array $config): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            $config['host'],
            $config['port'] ?? 5432,
            $config['database']
        );

        return new PDO(
            $dsn,
            $config['username'],
            $config['password'],
            $config['options'] ?? []
        );
    }
}
