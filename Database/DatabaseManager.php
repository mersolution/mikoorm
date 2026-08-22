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

use Miko\Core\Config;
use Miko\Database\ConnectionPool\ConnectionPool;
use Miko\Database\ConnectionPool\ConnectionPoolInterface;
use Miko\Database\Exceptions\DatabaseException;

/**
 * Database manager - replaces old Database singleton
 */
class DatabaseManager implements DatabaseInterface
{
    private ConnectionPoolInterface $pool;
    private array $queryLog = [];
    private bool $loggingEnabled = false;
    /** @var array<string, ConnectionInterface> */
    private array $pinned = [];

    public function __construct(ConnectionPoolInterface $pool)
    {
        $this->pool = $pool;
    }

    /**
     * Build a manager from Config/Database.php (or an explicit config array).
     */
    public static function fromConfig(?array $config = null): self
    {
        if ($config === null) {
            $config = Config::get('database');
            if (!is_array($config)) {
                $config = Config::get('Database');
            }
        }

        if (!is_array($config) || empty($config['connections']) || !is_array($config['connections'])) {
            throw new DatabaseException(
                'Database pool requires a connections array. Call Config::load() or pass config to DatabaseManager::fromConfig().'
            );
        }

        return new self(new ConnectionPool($config));
    }

    /**
     * @inheritDoc
     */
    public function connection(?string $name = null): ConnectionInterface
    {
        $name = $name ?? 'default';

        if (!isset($this->pinned[$name])) {
            $this->pinned[$name] = $this->pool->getConnection($name);
        }

        return $this->pinned[$name];
    }

    /**
     * Return pinned connections to the pool.
     */
    public function release(?string $name = null): void
    {
        if ($name === null) {
            foreach (array_keys($this->pinned) as $key) {
                $this->release($key);
            }
            return;
        }

        if (!isset($this->pinned[$name])) {
            return;
        }

        $this->pool->releaseConnection($this->pinned[$name]);
        unset($this->pinned[$name]);
    }

    /**
     * @inheritDoc
     */
    public function beginTransaction(): void
    {
        $this->connection()->beginTransaction();
    }

    /**
     * @inheritDoc
     */
    public function commit(): void
    {
        $this->connection()->commit();
    }

    /**
     * @inheritDoc
     */
    public function rollback(): void
    {
        $this->connection()->rollback();
    }

    /**
     * @inheritDoc
     */
    public function transaction(callable $callback, ?string $connection = null): mixed
    {
        $conn = $this->connection($connection);

        $conn->beginTransaction();

        try {
            $result = $callback($conn);
            $conn->commit();
            return $result;
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    /**
     * @inheritDoc
     */
    public function enableQueryLog(): void
    {
        $this->loggingEnabled = true;
    }

    /**
     * @inheritDoc
     */
    public function disableQueryLog(): void
    {
        $this->loggingEnabled = false;
    }

    /**
     * Log a query
     *
     * @param string $sql
     * @param array $bindings
     * @param float $time
     * @return void
     */
    public function logQuery(string $sql, array $bindings, float $time): void
    {
        if ($this->loggingEnabled) {
            $this->queryLog[] = [
                'sql' => $sql,
                'bindings' => $bindings,
                'time' => $time,
                'timestamp' => microtime(true),
            ];
        }
    }

    /**
     * Clear query log
     *
     * @return void
     */
    public function clearQueryLog(): void
    {
        $this->queryLog = [];
    }

    /**
     * Get connection pool
     *
     * @return ConnectionPoolInterface
     */
    public function getPool(): ConnectionPoolInterface
    {
        return $this->pool;
    }

    public function __destruct()
    {
        $this->release();
    }
}
