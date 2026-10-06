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

use Closure;
use Miko\Core\Database\DatabaseConfig;

/**
 * ConnectionResolver - one shared default connection for Model, Transaction, DB and builders.
 *
 * Resolution order:
 *   1. ConnectionResolver::setDefault($connection or fn() => $connection)
 *   2. the last DbConfig::...()->connect() connection
 *   3. Config/Database.php "default" connection (created once per process)
 */
final class ConnectionResolver
{
    private static ConnectionInterface|Closure|null $default = null;

    public static function setDefault(ConnectionInterface|Closure|null $connection): void
    {
        self::$default = $connection;
    }

    public static function hasDefault(): bool
    {
        return self::$default !== null;
    }

    public static function default(): ConnectionInterface
    {
        if (self::$default instanceof Closure) {
            self::$default = (self::$default)();
        }

        if (self::$default instanceof ConnectionInterface) {
            return self::$default;
        }

        $fromDbConfig = DbConfig::connection();
        if ($fromDbConfig !== null) {
            return $fromDbConfig;
        }

        return DatabaseConfig::createConnection();
    }

    /**
     * Named connection from Config/Database.php (null = default)
     */
    public static function connection(?string $name = null): ConnectionInterface
    {
        return $name === null ? self::default() : DatabaseConfig::createConnection($name);
    }

    public static function reset(): void
    {
        self::$default = null;
    }
}
