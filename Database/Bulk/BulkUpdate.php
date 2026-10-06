<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\Bulk;

use Miko\Database\ConnectionInterface;
use Miko\Database\Exceptions\DatabaseException;
use Miko\Database\ORM\Transaction;
use Miko\Database\Query\Grammar;

/**
 * Many single-row UPDATEs in one transaction, one prepared statement per column set
 *
 * (new BulkUpdate($connection))->table('Products')->keyColumn('Id')
 *     ->addUpdate(5, ['Price' => 10])->addUpdate(6, ['Price' => 12])->execute();
 */
class BulkUpdate
{
    private ConnectionInterface $connection;
    private string $table = '';
    private string $keyColumn = 'Id';
    /** @var array<int, array{0: mixed, 1: array}> */
    private array $updates = [];
    private int $batchSize = 1000;

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    public function table(string $table): self
    {
        $this->table = Grammar::assertTable($table);
        return $this;
    }

    public function keyColumn(string $column): self
    {
        $this->keyColumn = Grammar::assertReference($column);
        return $this;
    }

    public function addUpdate(mixed $keyValue, array $data): self
    {
        foreach (array_keys($data) as $column) {
            Grammar::assertReference((string) $column);
        }
        $this->updates[] = [$keyValue, $data];
        return $this;
    }

    /**
     * Kept for compatibility; all updates run in one transaction
     */
    public function batchSize(int $size): self
    {
        $this->batchSize = max(1, $size);
        return $this;
    }

    /**
     * @return int Number of affected rows
     */
    public function execute(): int
    {
        if ($this->updates === []) {
            return 0;
        }

        if ($this->table === '') {
            throw new DatabaseException('BulkUpdate needs table().');
        }

        $grammar = $this->connection->getGrammar();
        $table = $grammar->wrapTable($this->table);
        $key = $grammar->wrap($this->keyColumn);

        $affected = Transaction::run(function () use ($grammar, $table, $key) {
            $statements = [];
            $affected = 0;

            foreach ($this->updates as [$keyValue, $data]) {
                if ($data === []) {
                    continue;
                }

                $signature = implode(',', array_keys($data));
                if (!isset($statements[$signature])) {
                    $sets = array_map(fn($c) => $grammar->wrap((string) $c) . ' = ?', array_keys($data));
                    $statements[$signature] = $this->connection->prepare("UPDATE {$table} SET " . implode(', ', $sets) . " WHERE {$key} = ?");
                }

                $statement = $statements[$signature];
                $position = 1;
                foreach ($data as $value) {
                    $statement->bindValue($position++, $value);
                }
                $statement->bindValue($position, $keyValue);
                $statement->execute();
                $affected += $statement->rowCount();
            }

            return $affected;
        }, $this->connection);

        $this->updates = [];
        return $affected;
    }
}
