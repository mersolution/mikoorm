<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\ConnectionPool;

use Miko\Database\ConnectionInterface;

/**
 * Connection pool interface for managing database connections
 */
interface ConnectionPoolInterface
{
    /**
     * Get a connection from the pool
     *
     * @param string $name Connection name
     * @return ConnectionInterface
     */
    public function getConnection(string $name = 'default'): ConnectionInterface;

    /**
     * Release a connection back to the pool
     *
     * @param ConnectionInterface $connection
     * @return void
     */
    public function releaseConnection(ConnectionInterface $connection): void;

    /**
     * Get pool statistics
     *
     * @return array
     */
    public function getStats(): array;

    /**
     * Get active connections count
     *
     * @return int
     */
    public function getActiveCount(): int;

    /**
     * Get idle connections count
     *
     * @return int
     */
    public function getIdleCount(): int;

    /**
     * Close all connections
     *
     * @return void
     */
    public function closeAll(): void;

    /**
     * Remove dead connections from the pool
     *
     * @return int Number of connections removed
     */
    public function pruneDeadConnections(): int;
}
