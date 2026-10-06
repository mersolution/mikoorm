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
use Miko\Database\ConnectionFactory;
use Miko\Database\ConnectionInterface;
use Miko\Database\DatabaseManager;
use Miko\Database\Exceptions\DatabaseException;
use Miko\Database\Log\QueryLogger;
use Miko\Log\Logger;

/**
 * Database Config Helper - creates connections from Config/Database.php.
 *
 * Connections are created once per name and reused (one connection per
 * request instead of one per model class).
 */
class DatabaseConfig
{
    private static ?DatabaseManager $manager = null;
    private static array $connections = [];
    private static ?array $fileConfig = null;
    private static bool $loggerConfigured = false;

    /**
     * Get (or create) a connection. Uses DatabaseManager when pool.enabled is true.
     */
    public static function createConnection(?string $name = null): ConnectionInterface
    {
        $db = self::config();
        self::configureQueryLogger($db);

        if (ConnectionFactory::isTruthy($db['pool']['enabled'] ?? false)) {
            return self::manager()->connection($name);
        }

        $name = $name ?? self::defaultName();

        if (isset(self::$connections[$name])) {
            return self::$connections[$name];
        }

        return self::$connections[$name] = ConnectionFactory::make(self::connectionConfig($name));
    }

    /**
     * Name of the default connection
     */
    public static function defaultName(): string
    {
        $default = self::config()['default'] ?? 'mysql';
        return is_string($default) && $default !== '' ? $default : 'mysql';
    }

    /**
     * Full config of one connection (session settings merged in)
     */
    public static function connectionConfig(?string $name = null): array
    {
        $db = self::config();
        $name = $name ?? self::defaultName();
        $config = $db['connections'][$name] ?? null;

        if (!is_array($config)) {
            throw new DatabaseException("Database connection [{$name}] not configured.");
        }

        $config['driver'] = $config['driver'] ?? $name;

        foreach (['wait_timeout', 'interactive_timeout', 'persistent', 'time_names'] as $key) {
            if (!array_key_exists($key, $config) && isset($db['session'][$key])) {
                $config[$key] = $db['session'][$key];
            }
        }

        return $config;
    }

    public static function manager(): DatabaseManager
    {
        if (self::$manager === null) {
            self::$manager = DatabaseManager::fromConfig(self::config());
            register_shutdown_function(static function () {
                if (self::$manager !== null) {
                    self::$manager->release();
                    self::$manager = null;
                }
            });
        }

        return self::$manager;
    }

    /**
     * Database config: Config::get('database') or the bundled Config/Database.php
     */
    public static function config(): array
    {
        $config = Config::get('database');
        if (!is_array($config)) {
            $config = Config::get('Database');
        }

        if (is_array($config) && !empty($config['connections'])) {
            return $config;
        }

        if (self::$fileConfig === null) {
            $file = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'Database.php';
            $loaded = is_file($file) ? require $file : [];
            self::$fileConfig = is_array($loaded) ? $loaded : [];
        }

        return self::$fileConfig;
    }

    /**
     * Forget cached connections and config (tests, long running workers)
     */
    public static function reset(): void
    {
        foreach (self::$connections as $connection) {
            $connection->disconnect();
        }
        self::$connections = [];
        self::$fileConfig = null;
        self::$manager = null;
        self::$loggerConfigured = false;
    }

    private static function configureQueryLogger(array $db): void
    {
        if (self::$loggerConfigured) {
            return;
        }
        self::$loggerConfigured = true;

        $log = $db['query_log'] ?? null;
        if (!is_array($log) || !ConnectionFactory::isTruthy($log['enabled'] ?? false)) {
            return;
        }

        QueryLogger::enable();
        if (isset($log['slow_threshold'])) {
            QueryLogger::setSlowThreshold((float) $log['slow_threshold']);
        }
        if (!empty($log['log_file'])) {
            $file = (string) $log['log_file'];
            $isAbsolute = str_starts_with($file, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $file);
            QueryLogger::setLogFile($isAbsolute ? $file : Logger::getLogDir() . DIRECTORY_SEPARATOR . $file);
        }
    }
}
