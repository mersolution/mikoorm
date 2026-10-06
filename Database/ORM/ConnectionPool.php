<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 *
 * ConnectionPool - Database connection pool management
 * Similar to mersolutionCore ConnectionPool.cs
 */

namespace Miko\Database\ORM;

use Miko\Core\Database\DatabaseConfig;
use Miko\Database\ConnectionFactory;
use Miko\Database\ConnectionInterface;
use Miko\Database\Exceptions\DatabaseException;

/**
 * Static connection pool (per PHP process).
 *
 * Usage:
 * ConnectionPool::configure(minSize: 2, maxSize: 10);
 * ConnectionPool::setConfig(['driver' => 'mysql', 'host' => ..., ...]);
 * ConnectionPool::use(function ($connection) { ... });
 *
 * PHP runs one request per process, so when the pool is full acquire() fails
 * immediately instead of waiting for a release that can never happen.
 */
class ConnectionPool
{
    /** Idle connections older than this are pinged before reuse */
    private const PING_AFTER_SECONDS = 30;

    private static array $pool = [];
    private static array $inUse = [];
    private static int $minSize = 2;
    private static int $maxSize = 10;
    private static int $timeoutSeconds = 30;
    private static int $idleTimeoutSeconds = 300;
    private static bool $enabled = true;
    private static array $config = [];
    private static int $totalCreated = 0;
    private static int $totalAcquired = 0;
    private static int $totalReleased = 0;

    public static function configure(
        int $minSize = 2,
        int $maxSize = 10,
        int $timeoutSeconds = 30,
        int $idleTimeoutSeconds = 300
    ): void {
        self::$minSize = max(0, $minSize);
        self::$maxSize = max(1, $maxSize);
        self::$timeoutSeconds = $timeoutSeconds;
        self::$idleTimeoutSeconds = $idleTimeoutSeconds;
    }

    /**
     * Connection config for new pool connections (ConnectionFactory format).
     * Without it the default connection from Config/Database.php is used.
     */
    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }

    public static function enable(): void
    {
        self::$enabled = true;
    }

    public static function disable(): void
    {
        self::$enabled = false;
        self::clear();
    }

    public static function acquire(): ConnectionInterface
    {
        if (!self::$enabled) {
            self::$totalAcquired++;
            return self::createConnection();
        }

        self::cleanupIdle();

        while (!empty(self::$pool)) {
            $entry = array_pop(self::$pool);
            $connection = $entry['connection'];

            if (time() - $entry['returned_at'] > self::PING_AFTER_SECONDS && !$connection->isConnected()) {
                $connection->disconnect();
                continue;
            }

            return self::markInUse($connection);
        }

        if (count(self::$inUse) >= self::$maxSize) {
            throw new DatabaseException('Connection pool exhausted: ' . self::$maxSize . ' connections in use. Release connections or raise maxSize.');
        }

        return self::markInUse(self::createConnection());
    }

    public static function release(ConnectionInterface $connection): void
    {
        if (!self::$enabled) {
            return;
        }

        $id = spl_object_id($connection);
        if (!isset(self::$inUse[$id])) {
            return;
        }

        unset(self::$inUse[$id]);
        self::$totalReleased++;

        if ($connection->inTransaction()) {
            try {
                $connection->rollback();
            } catch (\Throwable $e) {
                $connection->disconnect();
                return;
            }
        }

        if (count(self::$pool) < self::$maxSize) {
            self::$pool[] = ['connection' => $connection, 'returned_at' => time()];
        } else {
            $connection->disconnect();
        }
    }

    /**
     * Use a connection with automatic release
     */
    public static function use(callable $callback): mixed
    {
        $connection = self::acquire();

        try {
            return $callback($connection);
        } finally {
            self::release($connection);
        }
    }

    public static function getStatus(): array
    {
        return [
            'enabled' => self::$enabled,
            'pool_size' => count(self::$pool),
            'in_use' => count(self::$inUse),
            'min_size' => self::$minSize,
            'max_size' => self::$maxSize,
            'total_created' => self::$totalCreated,
            'total_acquired' => self::$totalAcquired,
            'total_released' => self::$totalReleased,
            'timeout_seconds' => self::$timeoutSeconds,
            'idle_timeout_seconds' => self::$idleTimeoutSeconds,
        ];
    }

    public static function getStatusString(): string
    {
        $status = self::getStatus();
        return sprintf(
            'Pool: %d available, %d in use (max: %d), created: %d',
            $status['pool_size'],
            $status['in_use'],
            $status['max_size'],
            $status['total_created']
        );
    }

    /**
     * Close and forget all connections
     */
    public static function clear(): void
    {
        foreach (self::$pool as $entry) {
            $entry['connection']->disconnect();
        }
        self::$pool = [];
        self::$inUse = [];
    }

    /**
     * Open minSize idle connections up front
     */
    public static function warmUp(): void
    {
        while (count(self::$pool) < self::$minSize) {
            self::$pool[] = ['connection' => self::createConnection(), 'returned_at' => time()];
        }
    }

    private static function markInUse(ConnectionInterface $connection): ConnectionInterface
    {
        self::$inUse[spl_object_id($connection)] = ['connection' => $connection, 'acquired_at' => time()];
        self::$totalAcquired++;
        return $connection;
    }

    private static function createConnection(): ConnectionInterface
    {
        self::$totalCreated++;

        return ConnectionFactory::make(!empty(self::$config) ? self::$config : DatabaseConfig::connectionConfig());
    }

    private static function cleanupIdle(): void
    {
        $now = time();
        $kept = [];

        // Newest first so the minimum kept are the freshest
        foreach (array_reverse(self::$pool) as $entry) {
            if (count($kept) < self::$minSize || $now - $entry['returned_at'] <= self::$idleTimeoutSeconds) {
                $kept[] = $entry;
            } else {
                $entry['connection']->disconnect();
            }
        }

        self::$pool = array_reverse($kept);
    }
}
