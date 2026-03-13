<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * 
 * Transaction - Transaction helper for database operations
 * Similar to mersolutionCore MersoTransaction.cs
 */

namespace Miko\Database\ORM;

use Miko\Database\Connection;

/**
 * Transaction Helper
 * 
 * Usage:
 * // Simple transaction
 * Transaction::run(function() {
 *     $user = new User(['name' => 'Test']);
 *     $user->save();
 *     
 *     $order = new Order(['user_id' => $user->Id]);
 *     $order->save();
 * });
 * 
 * // With return value
 * $result = Transaction::run(function() {
 *     return User::create(['name' => 'Test']);
 * });
 * 
 * // Try run (returns bool)
 * $success = Transaction::tryRun(function() {
 *     // operations...
 * });
 */
class Transaction
{
    private static ?Connection $connection = null;
    private static int $transactionLevel = 0;

    /**
     * Set connection for transactions
     */
    public static function setConnection(Connection $connection): void
    {
        self::$connection = $connection;
    }

    /**
     * Get connection
     */
    private static function getConnection(): Connection
    {
        if (self::$connection === null) {
            self::$connection = Connection::getInstance();
        }
        return self::$connection;
    }

    /**
     * Run callback in transaction
     * 
     * @param callable $callback
     * @return mixed Return value from callback
     * @throws \Exception If transaction fails
     */
    public static function run(callable $callback): mixed
    {
        $connection = self::getConnection();
        
        self::$transactionLevel++;
        
        if (self::$transactionLevel === 1) {
            $connection->beginTransaction();
        }

        try {
            $result = $callback();
            
            if (self::$transactionLevel === 1) {
                $connection->commit();
            }
            
            self::$transactionLevel--;
            return $result;
            
        } catch (\Exception $e) {
            if (self::$transactionLevel === 1) {
                $connection->rollback();
            }
            self::$transactionLevel--;
            throw $e;
        }
    }

    /**
     * Try to run callback in transaction (returns bool)
     * 
     * @param callable $callback
     * @param \Exception|null $exception Output parameter for exception
     * @return bool True if successful, false if failed
     */
    public static function tryRun(callable $callback, ?\Exception &$exception = null): bool
    {
        try {
            self::run($callback);
            return true;
        } catch (\Exception $e) {
            $exception = $e;
            return false;
        }
    }

    /**
     * Begin transaction manually
     */
    public static function begin(): void
    {
        self::$transactionLevel++;
        
        if (self::$transactionLevel === 1) {
            self::getConnection()->beginTransaction();
        }
    }

    /**
     * Commit transaction manually
     */
    public static function commit(): void
    {
        if (self::$transactionLevel === 1) {
            self::getConnection()->commit();
        }
        
        if (self::$transactionLevel > 0) {
            self::$transactionLevel--;
        }
    }

    /**
     * Rollback transaction manually
     */
    public static function rollback(): void
    {
        if (self::$transactionLevel === 1) {
            self::getConnection()->rollback();
        }
        
        if (self::$transactionLevel > 0) {
            self::$transactionLevel--;
        }
    }

    /**
     * Get current transaction level (for nested transactions)
     */
    public static function getLevel(): int
    {
        return self::$transactionLevel;
    }

    /**
     * Check if currently in transaction
     */
    public static function inTransaction(): bool
    {
        return self::$transactionLevel > 0;
    }

    /**
     * Run callback with savepoint (for nested transactions)
     */
    public static function savepoint(string $name, callable $callback): mixed
    {
        $connection = self::getConnection();
        $pdo = $connection->getPdo();
        
        $pdo->exec("SAVEPOINT {$name}");
        
        try {
            $result = $callback();
            $pdo->exec("RELEASE SAVEPOINT {$name}");
            return $result;
        } catch (\Exception $e) {
            $pdo->exec("ROLLBACK TO SAVEPOINT {$name}");
            throw $e;
        }
    }
}
