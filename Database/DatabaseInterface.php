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

/**
 * Database manager interface
 */
interface DatabaseInterface
{
    /**
     * Get a database connection
     *
     * @param string|null $name Connection name
     * @return ConnectionInterface
     */
    public function connection(?string $name = null): ConnectionInterface;

    /**
     * Begin a transaction on the default connection
     *
     * @return void
     */
    public function beginTransaction(): void;

    /**
     * Commit the transaction on the default connection
     *
     * @return void
     */
    public function commit(): void;

    /**
     * Rollback the transaction on the default connection
     *
     * @return void
     */
    public function rollback(): void;

    /**
     * Execute a callback within a transaction
     *
     * @param callable $callback
     * @param string|null $connection
     * @return mixed
     * @throws \Throwable
     */
    public function transaction(callable $callback, ?string $connection = null): mixed;

    /**
     * Get the query log
     *
     * @return array
     */
    public function getQueryLog(): array;

    /**
     * Enable query logging
     *
     * @return void
     */
    public function enableQueryLog(): void;

    /**
     * Disable query logging
     *
     * @return void
     */
    public function disableQueryLog(): void;
}
