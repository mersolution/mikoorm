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

use Miko\Database\Connection;
use Miko\Database\ConnectionInterface;
use Miko\Database\Exceptions\DatabaseException;

/**
 * Connection pool implementation
 */
class ConnectionPool implements ConnectionPoolInterface
{
    private array $config;
    private array $pools = [];
    private array $activeConnections = [];
    private array $idleConnections = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @inheritDoc
     */
    public function getConnection(string $name = 'default'): ConnectionInterface
    {
        $poolConfig = $this->config['connections'][$name] ?? null;
        
        if ($poolConfig === null) {
            throw new DatabaseException("Connection configuration not found: {$name}");
        }

        // Check if we have idle connections
        if (!empty($this->idleConnections[$name])) {
            $connection = array_shift($this->idleConnections[$name]);
            
            // Return connection directly - let it fail on actual query if dead
            // This avoids unnecessary SELECT 1 on every connection fetch
            $this->activeConnections[$name][] = $connection;
            return $connection;
        }

        // Check max connections limit
        $maxConnections = $poolConfig['pool']['max'] ?? 10;
        $currentCount = $this->getActiveCount() + $this->getIdleCount();
        
        if ($currentCount >= $maxConnections) {
            // Wait for a connection to become available or throw exception
            throw new DatabaseException("Connection pool limit reached: {$maxConnections}");
        }

        // Create new connection
        $connection = $this->createConnection($poolConfig);
        $this->activeConnections[$name][] = $connection;

        return $connection;
    }

    /**
     * @inheritDoc
     */
    public function releaseConnection(ConnectionInterface $connection): void
    {
        // Find and remove from active connections
        foreach ($this->activeConnections as $name => &$connections) {
            $key = array_search($connection, $connections, true);
            if ($key !== false) {
                unset($connections[$key]);
                $connections = array_values($connections);
                
                // Add to idle connections directly (skip isConnected check for performance)
                // Dead connections will be detected on next use or during pruning
                if (!isset($this->idleConnections[$name])) {
                    $this->idleConnections[$name] = [];
                }
                $this->idleConnections[$name][] = $connection;
                
                return;
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function getStats(): array
    {
        return [
            'active' => $this->getActiveCount(),
            'idle' => $this->getIdleCount(),
            'total' => $this->getActiveCount() + $this->getIdleCount(),
            'by_connection' => [
                'active' => array_map('count', $this->activeConnections),
                'idle' => array_map('count', $this->idleConnections),
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function getActiveCount(): int
    {
        return array_sum(array_map('count', $this->activeConnections));
    }

    /**
     * @inheritDoc
     */
    public function getIdleCount(): int
    {
        return array_sum(array_map('count', $this->idleConnections));
    }

    /**
     * @inheritDoc
     */
    public function closeAll(): void
    {
        // Close all active connections
        foreach ($this->activeConnections as $connections) {
            foreach ($connections as $connection) {
                $connection->disconnect();
            }
        }

        // Close all idle connections
        foreach ($this->idleConnections as $connections) {
            foreach ($connections as $connection) {
                $connection->disconnect();
            }
        }

        $this->activeConnections = [];
        $this->idleConnections = [];
    }

    /**
     * @inheritDoc
     */
    public function pruneDeadConnections(): int
    {
        $removed = 0;

        // Check idle connections
        foreach ($this->idleConnections as $name => &$connections) {
            foreach ($connections as $key => $connection) {
                if (!$connection->isConnected()) {
                    $connection->disconnect();
                    unset($connections[$key]);
                    $removed++;
                }
            }
            $connections = array_values($connections);
        }

        return $removed;
    }

    /**
     * Create a new database connection
     *
     * @param array $config
     * @return ConnectionInterface
     */
    private function createConnection(array $config): ConnectionInterface
    {
        $dsn = sprintf(
            '%s:host=%s;port=%s;dbname=%s;charset=%s',
            $config['driver'] ?? 'mysql',
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? 3306,
            $config['database'] ?? '',
            $config['charset'] ?? 'utf8mb4'
        );

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // Add persistent connection if configured
        if (isset($config['persistent']) && $config['persistent']) {
            $options[\PDO::ATTR_PERSISTENT] = true;
        }

        $pdo = new \PDO(
            $dsn,
            $config['username'] ?? '',
            $config['password'] ?? '',
            $options
        );

        // Set charset and collation
        $charset = $config['charset'] ?? 'utf8mb4';
        if (isset($config['collation'])) {
            $pdo->exec("SET NAMES '{$charset}' COLLATE '{$config['collation']}'");
        } else {
            $pdo->exec("SET NAMES '{$charset}'");
        }

        // Set locale and session timeouts
        @$pdo->exec("SET lc_time_names = 'tr_TR'");
        $pdo->exec("SET SESSION wait_timeout = 28800");
        $pdo->exec("SET SESSION interactive_timeout = 28800");

        return new Connection($pdo, $config);
    }
}
