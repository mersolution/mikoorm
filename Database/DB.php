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

use Miko\Core\Async\Future;
use Miko\Database\Async\AsyncConnection;
use Miko\Database\ORM\Transaction;
use Miko\Database\Query\QueryBuilder;

/**
 * DB Facade - static access to the default connection
 */
class DB
{
    private static ?ConnectionInterface $connection = null;

    private function __construct() {}

    /**
     * Default connection (shared with models and Transaction)
     */
    public static function connection(): ConnectionInterface
    {
        return self::$connection ?? ConnectionResolver::default();
    }

    /**
     * Use a specific connection for the facade (null = default)
     */
    public static function setConnection(?ConnectionInterface $connection): void
    {
        self::$connection = $connection;
    }

    /**
     * Start a query builder for a table
     */
    public static function table(string $table, ?string $alias = null): QueryBuilder
    {
        return (new QueryBuilder(self::connection()))->from($table, $alias);
    }

    public static function query(string $sql, array $params = []): array
    {
        return self::connection()->execute($sql, $params)->all();
    }

    /**
     * Run a statement, returns affected rows
     */
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
        return $row === null ? null : reset($row);
    }

    public static function lastInsertId(): string|int
    {
        return self::connection()->lastInsertId();
    }

    // ========================================
    // Async (parallel on MySQL / MariaDB / PostgreSQL)
    // ========================================

    /**
     * query() as a Future of the rows
     *
     * @return Future<array>
     */
    public static function queryAsync(string $sql, array $params = []): Future
    {
        return AsyncConnection::select(self::connection(), $sql, $params);
    }

    /**
     * @return Future<?array>
     */
    public static function firstAsync(string $sql, array $params = []): Future
    {
        return self::queryAsync($sql, $params)->then(static fn(array $rows) => $rows[0] ?? null);
    }

    public static function scalarAsync(string $sql, array $params = []): Future
    {
        return self::firstAsync($sql, $params)->then(static fn(?array $row) => $row === null ? null : reset($row));
    }

    /**
     * true when *Async() queries really run in parallel on this connection
     * (false: SQLite, or no client fits the config)
     */
    public static function supportsParallelQueries(): bool
    {
        return AsyncConnection::isParallel(self::connection());
    }

    /**
     * Run a callback in a transaction (nested calls use savepoints)
     */
    public static function transaction(callable $callback): mixed
    {
        return Transaction::run($callback, self::connection());
    }

    public static function beginTransaction(): void
    {
        Transaction::begin(self::connection());
    }

    public static function commit(): void
    {
        Transaction::commit(self::connection());
    }

    public static function rollback(): void
    {
        Transaction::rollback(self::connection());
    }

    private function __clone() {}
}
