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

class BulkInsert
{
    private ConnectionInterface $connection;
    private string $table;
    private array $columns = [];
    private array $rows = [];
    private int $batchSize = 1000;

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    public function into(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    public function columns(array $columns): self
    {
        $this->columns = $columns;
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
        $this->batchSize = $size;
        return $this;
    }

    public function execute(): int
    {
        if (empty($this->rows)) {
            return 0;
        }

        $totalInserted = 0;
        $batches = array_chunk($this->rows, $this->batchSize);

        foreach ($batches as $batch) {
            $totalInserted += $this->insertBatch($batch);
        }

        $this->rows = [];
        return $totalInserted;
    }

    private function insertBatch(array $batch): int
    {
        $columns = implode(', ', array_map(fn($col) => "`{$col}`", $this->columns));
        $placeholders = '(' . implode(', ', array_fill(0, count($this->columns), '?')) . ')';
        $allPlaceholders = implode(', ', array_fill(0, count($batch), $placeholders));

        $sql = "INSERT INTO `{$this->table}` ({$columns}) VALUES {$allPlaceholders}";

        $values = [];
        foreach ($batch as $row) {
            foreach ($row as $value) {
                $values[] = $value;
            }
        }

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($values);

        return count($batch);
    }
}
