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
use Miko\Database\Drivers\DriverFactory;
use Miko\Database\Exceptions\DatabaseException;
use PDO;

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
        $key = $this->resolveName($name);
        $poolConfig = $this->connectionConfig($name);

        if (!empty($this->idleConnections[$key])) {
            $connection = array_shift($this->idleConnections[$key]);
            $this->activeConnections[$key][] = $connection;
            return $connection;
        }

        $maxConnections = (int) ($poolConfig['pool']['max'] ?? $this->config['pool']['max'] ?? 10);
        $currentCount = count($this->activeConnections[$key] ?? []) + count($this->idleConnections[$key] ?? []);

        if ($currentCount >= $maxConnections) {
            throw new DatabaseException("Connection pool limit reached: {$maxConnections}");
        }

        $connection = $this->createConnection($poolConfig);
        $this->activeConnections[$key][] = $connection;

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
     */
    private function createConnection(array $config): ConnectionInterface
    {
        $driverName = strtolower((string) ($config['driver'] ?? 'mysql'));
        $driver = DriverFactory::create($driverName);
        $options = $driver->getOptions();

        if (!empty($config['options']) && is_array($config['options'])) {
            $options = $config['options'] + $options;
        }

        $persistent = $config['persistent'] ?? false;
        if (is_string($persistent)) {
            $persistent = !in_array(strtolower($persistent), ['', '0', 'false', 'no', 'off'], true);
        }
        if ($persistent && $driverName !== 'sqlite') {
            $options[PDO::ATTR_PERSISTENT] = true;
        }

        $pdo = new PDO(
            $driver->getDsn($config),
            $config['username'] ?? null,
            $config['password'] ?? null,
            $options
        );

        $this->configureSession($pdo, $driverName, $config);

        return new Connection($pdo, $config);
    }

    private function resolveName(string $name): string
    {
        if ($name === 'default') {
            $default = $this->config['default'] ?? 'mysql';
            return is_string($default) && $default !== '' ? $default : 'mysql';
        }

        return $name;
    }

    private function connectionConfig(string $name): array
    {
        $resolved = $this->resolveName($name);
        $poolConfig = $this->config['connections'][$resolved]
            ?? $this->config['connections'][$name]
            ?? null;

        if (!is_array($poolConfig)) {
            throw new DatabaseException("Connection configuration not found: {$name}");
        }

        if (!isset($poolConfig['pool']) && isset($this->config['pool']) && is_array($this->config['pool'])) {
            $poolConfig['pool'] = $this->config['pool'];
        }

        if (!array_key_exists('persistent', $poolConfig) && isset($this->config['session']['persistent'])) {
            $poolConfig['persistent'] = $this->config['session']['persistent'];
        }

        return $poolConfig;
    }

    private function configureSession(PDO $pdo, string $driverName, array $config): void
    {
        if ($driverName === 'mysql') {
            $charset = $this->safeIdent($config['charset'] ?? 'utf8mb4');
            if (isset($config['collation'])) {
                $collation = $this->safeIdent((string) $config['collation']);
                $pdo->exec("SET NAMES '{$charset}' COLLATE '{$collation}'");
            } else {
                $pdo->exec("SET NAMES '{$charset}'");
            }

            $wait = (int) ($config['wait_timeout'] ?? $this->config['session']['wait_timeout'] ?? 28800);
            $interactive = (int) ($config['interactive_timeout'] ?? $this->config['session']['interactive_timeout'] ?? 28800);
            $pdo->exec("SET SESSION wait_timeout = {$wait}");
            $pdo->exec("SET SESSION interactive_timeout = {$interactive}");
            @$pdo->exec("SET lc_time_names = 'tr_TR'");
            return;
        }

        if ($driverName === 'pgsql') {
            $schema = $config['schema'] ?? null;
            if (is_string($schema) && $schema !== '' && preg_match('/^[A-Za-z0-9_]+$/', $schema)) {
                $pdo->exec('SET search_path TO ' . $schema);
            }
        }
    }

    private function safeIdent(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
            throw new DatabaseException('Invalid identifier in database config.');
        }

        return $value;
    }
}
