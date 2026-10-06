<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Core\Async;

/**
 * Async facade
 *
 *   [$users, $orders, $rates] = Async::all([
 *       User::where('Active', 1)->getAsync(),
 *       Order::query()->countAsync(),
 *       $http->getAsync('https://api.example.com/rates'),
 *   ]);
 *
 * Settings (Config/Database.php "async" or Async::configure()):
 *   max_connections  extra database connections per connection for parallel queries (default 4)
 *   timeout          seconds before an async query is cancelled (0 = no limit, default)
 *   mysql_driver     MySQL / MariaDB client: auto (mysqli if loaded, else built-in PHP client), extension, php
 *   pgsql_driver     PostgreSQL client: auto (pgsql extension if loaded, else built-in PHP client), extension, php
 *   sqlsrv_driver    SQL Server: auto / php (built-in TDS client), extension (one by one: pdo_sqlsrv has no async)
 */
final class Async
{
    private static ?array $settings = null;
    private static array $overrides = [];

    private function __construct()
    {
    }

    /**
     * Wait for every future; keys are kept. Throws the first error (in key order) after all finished.
     *
     * @param array<array-key, mixed> $items futures or plain values
     */
    public static function all(array $items): array
    {
        return Future::all($items)->await();
    }

    /**
     * Wait for every future and never throw:
     * ['status' => 'fulfilled', 'value' => ..] or ['status' => 'rejected', 'reason' => Throwable] per key
     *
     * @param array<array-key, mixed> $items futures or plain values
     */
    public static function allSettled(array $items): array
    {
        return Future::settleAll($items)->await();
    }

    public static function await(Future $future): mixed
    {
        return $future->await();
    }

    /**
     * Run a callable now and wrap its result / exception in a Future
     */
    public static function call(callable $callback): Future
    {
        return Future::call($callback);
    }

    // ========================================
    // Settings
    // ========================================

    /**
     * Override settings for this process: ['max_connections' => 4, 'timeout' => 10]
     */
    public static function configure(array $settings): void
    {
        self::$overrides = array_merge(self::$overrides, $settings);
        self::$settings = null;
    }

    public static function setting(string $name, mixed $default = null): mixed
    {
        return self::settings()[$name] ?? $default;
    }

    public static function settings(): array
    {
        if (self::$settings === null) {
            $file = [];
            if (class_exists(\Miko\Core\Database\DatabaseConfig::class)) {
                try {
                    $file = \Miko\Core\Database\DatabaseConfig::config()['async'] ?? [];
                } catch (\Throwable) {
                    $file = [];
                }
            }

            self::$settings = array_merge(
                ['max_connections' => 4, 'timeout' => 0, 'mysql_driver' => 'auto', 'pgsql_driver' => 'auto', 'sqlsrv_driver' => 'auto'],
                is_array($file) ? $file : [],
                self::$overrides
            );
        }

        return self::$settings;
    }

    /**
     * Back to the defaults / config file (tests)
     */
    public static function resetSettings(): void
    {
        self::$overrides = [];
        self::$settings = null;
    }
}
