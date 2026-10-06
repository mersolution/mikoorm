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

use Miko\Database\Connection;
use Miko\Database\ConnectionInterface;
use Miko\Database\Exceptions\DatabaseException;
use Miko\Database\ORM\Transaction;
use Miko\Database\Query\Grammar;

/**
 * Multi-row INSERT for a table
 *
 * (new BulkInsert($connection))->into('Logs')->columns(['Level', 'Message'])
 *     ->addRow(['Level' => 'info', 'Message' => 'a'])   // associative: matched by column name
 *     ->addRow(['warn', 'b'])                            // list: in columns() order
 *     ->execute();
 */
class BulkInsert
{
    private ConnectionInterface $connection;
    private string $table = '';
    private array $columns = [];
    private array $rows = [];
    private int $batchSize = 1000;

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    public function into(string $table): self
    {
        $this->table = Grammar::assertTable($table);
        return $this;
    }

    public function columns(array $columns): self
    {
        $this->columns = array_map(fn($c) => Grammar::assertReference((string) $c), array_values($columns));
        return $this;
    }

    public function addRow(array $values): self
    {
        $this->rows[] = $values;
        return $this;
    }

    public function addRows(array $rows): self
    {
        foreach ($rows as $row) {
            $this->rows[] = $row;
        }
        return $this;
    }

    public function batchSize(int $size): self
    {
        $this->batchSize = max(1, $size);
        return $this;
    }

    /**
     * @return int Number of inserted rows
     */
    public function execute(): int
    {
        if ($this->rows === []) {
            return 0;
        }

        if ($this->table === '' || $this->columns === []) {
            throw new DatabaseException('BulkInsert needs into() and columns().');
        }

        $values = array_map(fn(array $row) => $this->orderRow($row), $this->rows);
        $grammar = $this->connection->getGrammar();
        $maxParameters = $this->connection instanceof Connection ? $this->connection->maxParameters() : $grammar->maxParameters();
        $chunkSize = max(1, min($this->batchSize, intdiv($maxParameters, count($this->columns))));
        $placeholder = '(' . implode(', ', array_fill(0, count($this->columns), '?')) . ')';
        $prefix = 'INSERT INTO ' . $grammar->wrapTable($this->table)
            . ' (' . implode(', ', array_map(fn($c) => $grammar->wrap($c), $this->columns)) . ') VALUES ';

        $total = Transaction::run(function () use ($values, $chunkSize, $placeholder, $prefix) {
            $total = 0;
            foreach (array_chunk($values, $chunkSize) as $chunk) {
                $bindings = [];
                foreach ($chunk as $row) {
                    array_push($bindings, ...$row);
                }
                $this->connection->execute($prefix . implode(', ', array_fill(0, count($chunk), $placeholder)), $bindings);
                $total += count($chunk);
            }
            return $total;
        }, $this->connection);

        $this->rows = [];
        return $total;
    }

    /**
     * Row values in columns() order
     */
    private function orderRow(array $row): array
    {
        if (array_is_list($row)) {
            if (count($row) !== count($this->columns)) {
                throw new DatabaseException('BulkInsert row has ' . count($row) . ' values, expected ' . count($this->columns) . '.');
            }
            return $row;
        }

        $unknown = array_diff(array_keys($row), $this->columns);
        if ($unknown !== []) {
            throw new DatabaseException('BulkInsert row has unknown columns: ' . implode(', ', $unknown));
        }

        $ordered = [];
        foreach ($this->columns as $column) {
            $ordered[] = $row[$column] ?? null;
        }
        return $ordered;
    }
}
