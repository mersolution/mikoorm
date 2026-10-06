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
use Miko\Database\ORM\Transaction;

/**
 * Transaction Manager - object wrapper around Transaction for one connection
 * (shares the same nesting / savepoint state as Transaction::run()).
 */
class TransactionManager
{
    private ConnectionInterface $connection;

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Begin a transaction, or a savepoint when one is already open
     */
    public function begin(): void
    {
        Transaction::begin($this->connection);
    }

    /**
     * Commit the transaction or release the innermost savepoint
     */
    public function commit(): void
    {
        Transaction::commit($this->connection);
    }

    /**
     * Roll back the transaction or to the innermost savepoint
     */
    public function rollback(): void
    {
        Transaction::rollback($this->connection);
    }

    public function transaction(callable $callback): mixed
    {
        return Transaction::run($callback, $this->connection);
    }

    public function getTransactionLevel(): int
    {
        return Transaction::getLevel($this->connection);
    }

    public function inTransaction(): bool
    {
        return Transaction::inTransaction($this->connection);
    }
}
