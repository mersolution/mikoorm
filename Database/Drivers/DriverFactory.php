<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 */

namespace Miko\Database\Drivers;

use Miko\Database\Exceptions\DatabaseException;

/**
 * Database Driver Factory
 */
class DriverFactory
{
    private static array $drivers = [
        'mysql' => MySqlDriver::class,
        'pgsql' => PostgreSqlDriver::class,
        'sqlite' => SqliteDriver::class,
        'sqlsrv' => SqlServerDriver::class,
    ];

    /**
     * Create driver instance
     */
    public static function create(string $driver): DriverInterface
    {
        $driver = strtolower($driver);

        if (!isset(self::$drivers[$driver])) {
            throw new DatabaseException("Unsupported database driver: {$driver}");
        }

        $class = self::$drivers[$driver];
        return new $class();
    }

    /**
     * Register custom driver
     */
    public static function register(string $name, string $class): void
    {
        if (!is_subclass_of($class, DriverInterface::class)) {
            throw new DatabaseException("Driver must implement DriverInterface");
        }

        self::$drivers[strtolower($name)] = $class;
    }

    /**
     * Get available drivers
     */
    public static function getAvailableDrivers(): array
    {
        return array_keys(self::$drivers);
    }
}
