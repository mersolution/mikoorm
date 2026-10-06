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

use Miko\Core\Database\DatabaseConfig;
use Miko\Database\ConnectionPool\ConnectionPool;
use Miko\Database\ConnectionPool\ConnectionPoolInterface;
use Miko\Database\Exceptions\DatabaseException;
use Miko\Database\ORM\Transaction;

/**
 * Database manager - named connections from a pool, pinned per request
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
        $config ??= DatabaseConfig::config();

        if (empty($config['connections']) || !is_array($config['connections'])) {
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

    public function beginTransaction(): void
    {
        Transaction::begin($this->connection());
    }

    public function commit(): void
    {
        Transaction::commit($this->connection());
    }

    public function rollback(): void
    {
        Transaction::rollback($this->connection());
    }

    /**
     * Run a callback in a transaction (nested calls use savepoints)
     */
    public function transaction(callable $callback, ?string $connection = null): mixed
    {
        return Transaction::run($callback, $this->connection($connection));
    }

    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    public function enableQueryLog(): void
    {
        $this->loggingEnabled = true;
    }

    public function disableQueryLog(): void
    {
        $this->loggingEnabled = false;
    }

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

    public function clearQueryLog(): void
    {
        $this->queryLog = [];
    }

    public function getPool(): ConnectionPoolInterface
    {
        return $this->pool;
    }

    public function __destruct()
    {
        $this->release();
    }
}
