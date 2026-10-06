<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\Async;

use Miko\Core\Async\Async;
use Miko\Core\Async\Future;
use Miko\Database\Connection;
use Miko\Database\ConnectionFactory;
use Miko\Database\ConnectionInterface;

/**
 * Entry point of every *Async() query method.
 *
 * Runs the query in parallel: MySQL / MariaDB and PostgreSQL with their PHP extension
 * (mysqli / pgsql) or the built-in client, SQL Server with the built-in TDS client.
 * It runs right away on the main connection - same result, just not parallel - when
 *  - the driver has no server to talk to (SQLite) or no client fits (SQL Server with
 *    Windows authentication, MySQL charset other than utf8mb4 / utf8 / latin1 / ascii
 *    without mysqli)
 *  - the connection is inside a transaction (the query must see the transaction's changes)
 *  - async is switched off: Async::configure(['enabled' => false])
 */
final class AsyncConnection
{
    private function __construct()
    {
    }

    /**
     * Future of the result rows
     */
    public static function select(ConnectionInterface $connection, string $sql, array $bindings = []): Future
    {
        $driver = self::driver($connection);

        if ($driver === null || $connection->inTransaction() || !$driver->canRun($sql, $bindings)) {
            return Future::call(static fn() => $connection->execute($sql, $bindings)->all());
        }

        return $driver->submit($sql, $bindings);
    }

    /**
     * true when *Async() queries of this connection really run in parallel
     */
    public static function isParallel(ConnectionInterface $connection): bool
    {
        return self::driver($connection) !== null;
    }

    private static function driver(ConnectionInterface $connection): ?AsyncDriver
    {
        // "enabled" may come from .env as a string ("false", "0", "off")
        if (!$connection instanceof Connection || !ConnectionFactory::isTruthy(Async::setting('enabled', true))) {
            return null;
        }
        return $connection->asyncDriver();
    }
}
