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

class BulkUpdate
{
    private ConnectionInterface $connection;
    private string $table;
    private string $keyColumn;
    private array $updates = [];
    private int $batchSize = 1000;

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    public function table(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    public function keyColumn(string $column): self
    {
        $this->keyColumn = $column;
        return $this;
    }

    public function addUpdate($keyValue, array $data): self
    {
        $this->updates[$keyValue] = $data;
        return $this;
    }

    public function batchSize(int $size): self
    {
        $this->batchSize = $size;
        return $this;
    }

    public function execute(): int
    {
        if (empty($this->updates)) {
            return 0;
        }

        $totalUpdated = 0;
        $batches = array_chunk($this->updates, $this->batchSize, true);

        foreach ($batches as $batch) {
            foreach ($batch as $keyValue => $data) {
                $setClauses = [];
                $values = [];

                foreach ($data as $column => $value) {
                    $setClauses[] = "`{$column}` = ?";
                    $values[] = $value;
                }

                $values[] = $keyValue;
                $sql = "UPDATE `{$this->table}` SET " . implode(', ', $setClauses) . " WHERE `{$this->keyColumn}` = ?";

                $stmt = $this->connection->prepare($sql);
                $stmt->execute($values);
                $totalUpdated++;
            }
        }

        $this->updates = [];
        return $totalUpdated;
    }
}
