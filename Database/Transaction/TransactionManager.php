<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\Transaction;

use Miko\Database\ConnectionInterface;
use Miko\Database\Exceptions\DatabaseException;

/**
 * Transaction Manager - Handles database transactions with savepoint support
 */
class TransactionManager
{
    private ConnectionInterface $connection;
    private int $transactionLevel = 0;
    private array $savepoints = [];

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Begin a new transaction or create a savepoint
     */
    public function begin(): void
    {
        if ($this->transactionLevel === 0) {
            $this->connection->beginTransaction();
        } else {
            $savepointName = $this->createSavepointName();
            $this->connection->execute("SAVEPOINT {$savepointName}");
            $this->savepoints[] = $savepointName;
        }

        $this->transactionLevel++;
    }

    /**
     * Commit the transaction or release savepoint
     */
    public function commit(): void
    {
        if ($this->transactionLevel === 0) {
            throw new DatabaseException("No active transaction to commit");
        }

        $this->transactionLevel--;

        if ($this->transactionLevel === 0) {
            $this->connection->commit();
            $this->savepoints = [];
        } else {
            $savepointName = array_pop($this->savepoints);
            if ($savepointName) {
                $this->connection->execute("RELEASE SAVEPOINT {$savepointName}");
            }
        }
    }

    /**
     * Rollback the transaction or to savepoint
     */
    public function rollback(): void
    {
        if ($this->transactionLevel === 0) {
            throw new DatabaseException("No active transaction to rollback");
        }

        $this->transactionLevel--;

        if ($this->transactionLevel === 0) {
            $this->connection->rollback();
            $this->savepoints = [];
        } else {
            $savepointName = array_pop($this->savepoints);
            if ($savepointName) {
                $this->connection->execute("ROLLBACK TO SAVEPOINT {$savepointName}");
            }
        }
    }

    /**
     * Execute callback within transaction
     */
    public function transaction(callable $callback): mixed
    {
        $this->begin();

        try {
            $result = $callback($this->connection);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Get current transaction level
     */
    public function getTransactionLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * Check if in transaction
     */
    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0;
    }

    /**
     * Create savepoint name
     */
    private function createSavepointName(): string
    {
        return 'savepoint_' . $this->transactionLevel . '_' . uniqid();
    }
}
