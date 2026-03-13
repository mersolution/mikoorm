<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * 
 * ConnectionPool - Database connection pool management
 * Similar to mersolutionCore ConnectionPool.cs
 */

namespace Miko\Database\ORM;

use Miko\Database\Connection;
use PDO;

/**
 * Connection Pool Manager
 * 
 * Usage:
 * ConnectionPool::configure(minSize: 5, maxSize: 100, timeoutSeconds: 30);
 * $connection = ConnectionPool::acquire();
 * // use connection...
 * ConnectionPool::release($connection);
 * 
 * // Or use with callback
 * ConnectionPool::use(function($connection) {
 *     // use connection...
 * });
 */
class ConnectionPool
{
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

    /**
     * Configure connection pool
     */
    public static function configure(
        int $minSize = 2,
        int $maxSize = 10,
        int $timeoutSeconds = 30,
        int $idleTimeoutSeconds = 300
    ): void {
        self::$minSize = $minSize;
        self::$maxSize = $maxSize;
        self::$timeoutSeconds = $timeoutSeconds;
        self::$idleTimeoutSeconds = $idleTimeoutSeconds;
    }

    /**
     * Set database configuration
     */
    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }

    /**
     * Enable connection pooling
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Disable connection pooling
     */
    public static function disable(): void
    {
        self::$enabled = false;
        self::clear();
    }

    /**
     * Acquire a connection from pool
     */
    public static function acquire(): Connection
    {
        if (!self::$enabled) {
            return self::createConnection();
        }

        // Clean up idle connections
        self::cleanupIdle();

        // Try to get from pool
        if (!empty(self::$pool)) {
            $entry = array_pop(self::$pool);
            $connection = $entry['connection'];
            
            // Verify connection is still alive
            if (self::isAlive($connection)) {
                $id = spl_object_id($connection);
                self::$inUse[$id] = [
                    'connection' => $connection,
                    'acquired_at' => time()
                ];
                self::$totalAcquired++;
                return $connection;
            }
        }

        // Create new if under max
        if (count(self::$inUse) < self::$maxSize) {
            $connection = self::createConnection();
            $id = spl_object_id($connection);
            self::$inUse[$id] = [
                'connection' => $connection,
                'acquired_at' => time()
            ];
            self::$totalAcquired++;
            return $connection;
        }

        // Wait for available connection
        $startTime = time();
        while (time() - $startTime < self::$timeoutSeconds) {
            usleep(100000); // 100ms
            
            if (!empty(self::$pool)) {
                $entry = array_pop(self::$pool);
                $connection = $entry['connection'];
                
                if (self::isAlive($connection)) {
                    $id = spl_object_id($connection);
                    self::$inUse[$id] = [
                        'connection' => $connection,
                        'acquired_at' => time()
                    ];
                    self::$totalAcquired++;
                    return $connection;
                }
            }
        }

        throw new \RuntimeException("Connection pool timeout: no available connections");
    }

    /**
     * Release connection back to pool
     */
    public static function release(Connection $connection): void
    {
        if (!self::$enabled) {
            return;
        }

        $id = spl_object_id($connection);
        
        if (isset(self::$inUse[$id])) {
            unset(self::$inUse[$id]);
            self::$totalReleased++;
            
            // Return to pool if under max
            if (count(self::$pool) < self::$maxSize && self::isAlive($connection)) {
                self::$pool[] = [
                    'connection' => $connection,
                    'returned_at' => time()
                ];
            }
        }
    }

    /**
     * Use connection with callback (auto-release)
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

    /**
     * Get pool status
     */
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
            'idle_timeout_seconds' => self::$idleTimeoutSeconds
        ];
    }

    /**
     * Get status as string
     */
    public static function getStatusString(): string
    {
        $status = self::getStatus();
        return sprintf(
            "Pool: %d available, %d in use (max: %d), created: %d",
            $status['pool_size'],
            $status['in_use'],
            $status['max_size'],
            $status['total_created']
        );
    }

    /**
     * Clear all connections
     */
    public static function clear(): void
    {
        self::$pool = [];
        self::$inUse = [];
    }

    /**
     * Warm up pool with minimum connections
     */
    public static function warmUp(): void
    {
        while (count(self::$pool) < self::$minSize) {
            $connection = self::createConnection();
            self::$pool[] = [
                'connection' => $connection,
                'returned_at' => time()
            ];
        }
    }

    /**
     * Create new connection
     */
    private static function createConnection(): Connection
    {
        self::$totalCreated++;
        
        if (!empty(self::$config)) {
            return new Connection(
                self::$config['dsn'] ?? '',
                self::$config['username'] ?? null,
                self::$config['password'] ?? null,
                self::$config['options'] ?? []
            );
        }
        
        return Connection::getInstance();
    }

    /**
     * Check if connection is alive
     */
    private static function isAlive(Connection $connection): bool
    {
        try {
            $pdo = $connection->getPdo();
            $pdo->query('SELECT 1');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Clean up idle connections
     */
    private static function cleanupIdle(): void
    {
        $now = time();
        $keepMinimum = self::$minSize;
        
        self::$pool = array_filter(self::$pool, function($entry) use ($now, &$keepMinimum) {
            // Keep minimum connections
            if ($keepMinimum > 0) {
                $keepMinimum--;
                return true;
            }
            
            // Remove if idle too long
            if ($now - $entry['returned_at'] > self::$idleTimeoutSeconds) {
                return false;
            }
            
            return true;
        });
        
        // Re-index array
        self::$pool = array_values(self::$pool);
    }
}
