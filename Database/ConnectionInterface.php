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

use PDO;

/**
 * Database connection interface
 */
interface ConnectionInterface
{
    /**
     * Get the underlying PDO instance
     *
     * @return PDO
     */
    public function getPdo(): PDO;

    /**
     * Prepare a SQL statement
     *
     * @param string $sql The SQL query
     * @return StatementInterface
     */
    public function prepare(string $sql): StatementInterface;

    /**
     * Execute a SQL statement
     *
     * @param string $sql The SQL query
     * @param array $params Query parameters
     * @return ResultInterface
     */
    public function execute(string $sql, array $params = []): ResultInterface;

    /**
     * Begin a transaction
     *
     * @return void
     */
    public function beginTransaction(): void;

    /**
     * Commit the current transaction
     *
     * @return void
     */
    public function commit(): void;

    /**
     * Rollback the current transaction
     *
     * @return void
     */
    public function rollback(): void;

    /**
     * Check if a transaction is active
     *
     * @return bool
     */
    public function inTransaction(): bool;

    /**
     * Check if the connection is alive
     *
     * @return bool
     */
    public function isConnected(): bool;

    /**
     * Reconnect to the database
     *
     * @return void
     */
    public function reconnect(): void;

    /**
     * Disconnect from the database
     *
     * @return void
     */
    public function disconnect(): void;

    /**
     * Get the last inserted ID
     *
     * @return string|int
     */
    public function lastInsertId(): string|int;
}
