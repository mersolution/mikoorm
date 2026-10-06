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

use Miko\Database\Query\Grammar;
use PDO;

/**
 * Database connection interface
 */
interface ConnectionInterface
{
    /**
     * Get the underlying PDO instance
     */
    public function getPdo(): PDO;

    /**
     * Prepare a SQL statement
     */
    public function prepare(string $sql): StatementInterface;

    /**
     * Execute a SQL statement (positional "?" or named ":name" parameters)
     */
    public function execute(string $sql, array $params = []): ResultInterface;

    /**
     * Begin a transaction
     */
    public function beginTransaction(): void;

    /**
     * Commit the current transaction
     */
    public function commit(): void;

    /**
     * Rollback the current transaction
     */
    public function rollback(): void;

    /**
     * Check if a transaction is active
     */
    public function inTransaction(): bool;

    /**
     * Check if the connection is alive
     */
    public function isConnected(): bool;

    /**
     * Reconnect to the database
     */
    public function reconnect(): void;

    /**
     * Disconnect from the database
     */
    public function disconnect(): void;

    /**
     * Get the last inserted ID
     */
    public function lastInsertId(?string $sequence = null): string|int;

    /**
     * PDO driver name: mysql, pgsql, sqlite, sqlsrv
     */
    public function getDriverName(): string;

    /**
     * SQL grammar for this connection's driver
     */
    public function getGrammar(): Grammar;
}
