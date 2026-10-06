<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 *
 * Transaction - Transaction helper for database operations
 * Similar to mersolutionCore MersoTransaction.cs
 */

namespace Miko\Database\ORM;

use Miko\Database\ConnectionInterface;
use Miko\Database\ConnectionResolver;
use Miko\Database\Exceptions\DatabaseException;
use Miko\Database\Query\Grammar;

/**
 * Transaction Helper
 *
 * Uses the same default connection as the models, so model writes inside
 * run() are part of the transaction. Nested run() calls use savepoints:
 * an inner failure rolls back only the inner block.
 *
 * Transaction::run(function () {
 *     $user = User::create(['Name' => 'Test']);
 *     Order::create(['UserId' => $user->Id]);
 * });
 */
class Transaction
{
    private static ?ConnectionInterface $connection = null;

    /**
     * Open levels per connection: 'TX' for the real transaction, savepoint names above it
     *
     * @var array<int, string[]>
     */
    private static array $stacks = [];

    /**
     * Use a specific connection by default (null = model default connection)
     */
    public static function setConnection(?ConnectionInterface $connection): void
    {
        self::$connection = $connection;
    }

    private static function resolve(?ConnectionInterface $connection): ConnectionInterface
    {
        return $connection ?? self::$connection ?? ConnectionResolver::default();
    }

    /**
     * Run a callback in a transaction; any Throwable rolls back and is rethrown
     */
    public static function run(callable $callback, ?ConnectionInterface $connection = null): mixed
    {
        $connection = self::resolve($connection);
        self::begin($connection);

        try {
            $result = $callback($connection);
        } catch (\Throwable $e) {
            try {
                self::rollback($connection);
            } catch (\Throwable $rollbackError) {
                // keep the original error
            }
            throw $e;
        }

        self::commit($connection);

        return $result;
    }

    /**
     * Run and return true/false instead of throwing
     */
    public static function tryRun(callable $callback, ?\Throwable &$exception = null, ?ConnectionInterface $connection = null): bool
    {
        try {
            self::run($callback, $connection);
            return true;
        } catch (\Throwable $e) {
            $exception = $e;
            return false;
        }
    }

    public static function begin(?ConnectionInterface $connection = null): void
    {
        $connection = self::resolve($connection);
        $id = spl_object_id($connection);
        $stack = self::$stacks[$id] ?? [];

        if ($stack === [] && !$connection->inTransaction()) {
            $connection->beginTransaction();
            $stack[] = 'TX';
        } else {
            $name = 'miko_sp_' . count($stack) . '_' . bin2hex(random_bytes(3));
            $connection->getPdo()->exec($connection->getGrammar()->savepoint($name));
            $stack[] = $name;
        }

        self::$stacks[$id] = $stack;
    }

    public static function commit(?ConnectionInterface $connection = null): void
    {
        $connection = self::resolve($connection);
        $top = self::pop($connection);

        if ($top === 'TX') {
            // DDL on MySQL commits implicitly; nothing left to commit then
            if ($connection->inTransaction()) {
                $connection->commit();
            }
            return;
        }

        $release = $connection->getGrammar()->releaseSavepoint($top);
        if ($release !== null && $connection->inTransaction()) {
            $connection->getPdo()->exec($release);
        }
    }

    public static function rollback(?ConnectionInterface $connection = null): void
    {
        $connection = self::resolve($connection);
        $top = self::pop($connection);

        if ($top === 'TX') {
            if ($connection->inTransaction()) {
                $connection->rollback();
            }
            return;
        }

        if ($connection->inTransaction()) {
            $connection->getPdo()->exec($connection->getGrammar()->rollbackToSavepoint($top));
        }
    }

    /**
     * Current nesting level (0 = no transaction)
     */
    public static function getLevel(?ConnectionInterface $connection = null): int
    {
        return count(self::$stacks[spl_object_id(self::resolve($connection))] ?? []);
    }

    public static function inTransaction(?ConnectionInterface $connection = null): bool
    {
        return self::getLevel($connection) > 0;
    }

    /**
     * Run a callback inside a named savepoint (requires an open transaction)
     */
    public static function savepoint(string $name, callable $callback, ?ConnectionInterface $connection = null): mixed
    {
        $connection = self::resolve($connection);
        Grammar::assertIdentifier($name, 'savepoint name');
        $grammar = $connection->getGrammar();
        $pdo = $connection->getPdo();

        $pdo->exec($grammar->savepoint($name));

        try {
            $result = $callback($connection);
        } catch (\Throwable $e) {
            $pdo->exec($grammar->rollbackToSavepoint($name));
            throw $e;
        }

        $release = $grammar->releaseSavepoint($name);
        if ($release !== null) {
            $pdo->exec($release);
        }

        return $result;
    }

    private static function pop(ConnectionInterface $connection): string
    {
        $id = spl_object_id($connection);

        if (empty(self::$stacks[$id])) {
            throw new DatabaseException('No active transaction to commit or roll back.');
        }

        $top = array_pop(self::$stacks[$id]);
        if (self::$stacks[$id] === []) {
            unset(self::$stacks[$id]);
        }

        return $top;
    }
}
