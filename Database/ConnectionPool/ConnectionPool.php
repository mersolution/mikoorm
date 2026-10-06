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

use Miko\Database\ConnectionFactory;
use Miko\Database\ConnectionInterface;
use Miko\Database\Exceptions\DatabaseException;

/**
 * Connection pool (per PHP process). Real reuse across requests comes from
 * PDO persistent connections ("persistent" => true).
 */
class ConnectionPool implements ConnectionPoolInterface
{
    /** Idle connections older than this are pinged before reuse */
    private const PING_AFTER_SECONDS = 30;

    private array $config;
    /** @var array<string, ConnectionInterface[]> */
    private array $activeConnections = [];
    /** @var array<string, array<int, array{connection: ConnectionInterface, since: int}>> */
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

        while (!empty($this->idleConnections[$key])) {
            $entry = array_pop($this->idleConnections[$key]);
            $connection = $entry['connection'];

            if (time() - $entry['since'] > self::PING_AFTER_SECONDS && !$connection->isConnected()) {
                $connection->disconnect();
                continue;
            }

            $this->activeConnections[$key][] = $connection;
            return $connection;
        }

        $maxConnections = (int) ($poolConfig['pool']['max'] ?? $this->config['pool']['max'] ?? 10);
        $currentCount = count($this->activeConnections[$key] ?? []);

        if ($currentCount >= $maxConnections) {
            throw new DatabaseException("Connection pool limit reached: {$maxConnections}");
        }

        $connection = ConnectionFactory::make($poolConfig);
        $this->activeConnections[$key][] = $connection;

        return $connection;
    }

    /**
     * @inheritDoc
     */
    public function releaseConnection(ConnectionInterface $connection): void
    {
        foreach (array_keys($this->activeConnections) as $name) {
            $index = array_search($connection, $this->activeConnections[$name], true);
            if ($index === false) {
                continue;
            }

            unset($this->activeConnections[$name][$index]);
            $this->activeConnections[$name] = array_values($this->activeConnections[$name]);

            // Never hand an open transaction to the next user
            if ($connection->inTransaction()) {
                try {
                    $connection->rollback();
                } catch (\Throwable $e) {
                    $connection->disconnect();
                    return;
                }
            }

            $this->idleConnections[$name][] = ['connection' => $connection, 'since' => time()];
            return;
        }
    }

    public function getStats(): array
    {
        return [
            'active' => $this->getActiveCount(),
            'idle' => $this->getIdleCount(),
            'total' => $this->getActiveCount() + $this->getIdleCount(),
            'by_connection' => [
                'active' => array_map('count', $this->activeConnections),
                'idle' => array_map('count', $this->idleConnections),
            ],
        ];
    }

    public function getActiveCount(): int
    {
        return array_sum(array_map('count', $this->activeConnections));
    }

    public function getIdleCount(): int
    {
        return array_sum(array_map('count', $this->idleConnections));
    }

    public function closeAll(): void
    {
        foreach ($this->activeConnections as $connections) {
            foreach ($connections as $connection) {
                $connection->disconnect();
            }
        }

        foreach ($this->idleConnections as $entries) {
            foreach ($entries as $entry) {
                $entry['connection']->disconnect();
            }
        }

        $this->activeConnections = [];
        $this->idleConnections = [];
    }

    public function pruneDeadConnections(): int
    {
        $removed = 0;

        foreach (array_keys($this->idleConnections) as $name) {
            $alive = [];
            foreach ($this->idleConnections[$name] as $entry) {
                if ($entry['connection']->isConnected()) {
                    $alive[] = $entry;
                } else {
                    $entry['connection']->disconnect();
                    $removed++;
                }
            }
            $this->idleConnections[$name] = $alive;
        }

        return $removed;
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
        $poolConfig = $this->config['connections'][$resolved] ?? $this->config['connections'][$name] ?? null;

        if (!is_array($poolConfig)) {
            throw new DatabaseException("Connection configuration not found: {$name}");
        }

        $poolConfig['driver'] = $poolConfig['driver'] ?? $resolved;

        if (!isset($poolConfig['pool']) && isset($this->config['pool']) && is_array($this->config['pool'])) {
            $poolConfig['pool'] = $this->config['pool'];
        }

        foreach (['wait_timeout', 'interactive_timeout', 'persistent', 'time_names'] as $key) {
            if (!array_key_exists($key, $poolConfig) && isset($this->config['session'][$key])) {
                $poolConfig[$key] = $this->config['session'][$key];
            }
        }

        return $poolConfig;
    }
}
