<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * 
 * BulkOperations - Bulk insert/update operations for performance
 * Similar to mersolutionCore BulkOperations.cs
 */

namespace Miko\Database\ORM;

use Miko\Database\Connection;

/**
 * Bulk Operations for high-performance database operations
 * 
 * Usage:
 * BulkOperations::insert(User::class, $users);
 * BulkOperations::update(User::class, $users, 'Id');
 * BulkOperations::upsert(User::class, $users, 'Email');
 */
class BulkOperations
{
    private static int $chunkSize = 1000;

    /**
     * Set chunk size for bulk operations
     */
    public static function setChunkSize(int $size): void
    {
        self::$chunkSize = $size;
    }

    /**
     * Bulk insert records
     * 
     * @param string $modelClass Model class name
     * @param array $records Array of associative arrays or Model instances
     * @return int Number of inserted records
     */
    public static function insert(string $modelClass, array $records): int
    {
        if (empty($records)) {
            return 0;
        }

        $model = new $modelClass();
        $table = $modelClass::getTable();
        $connection = $model->getConnection();

        // Convert models to arrays
        $data = self::normalizeRecords($records);
        
        if (empty($data)) {
            return 0;
        }

        $columns = array_keys($data[0]);
        $totalInserted = 0;

        // Process in chunks
        foreach (array_chunk($data, self::$chunkSize) as $chunk) {
            $placeholders = [];
            $values = [];

            foreach ($chunk as $row) {
                $rowPlaceholders = array_fill(0, count($columns), '?');
                $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
                
                foreach ($columns as $col) {
                    $values[] = $row[$col] ?? null;
                }
            }

            $sql = sprintf(
                "INSERT INTO %s (%s) VALUES %s",
                $table,
                implode(', ', $columns),
                implode(', ', $placeholders)
            );

            $result = $connection->execute($sql, $values);
            $totalInserted += count($chunk);
        }

        return $totalInserted;
    }

    /**
     * Bulk update records
     * 
     * @param string $modelClass Model class name
     * @param array $records Array of associative arrays with primary key
     * @param string $keyColumn Primary key column name
     * @return int Number of updated records
     */
    public static function update(string $modelClass, array $records, string $keyColumn = 'Id'): int
    {
        if (empty($records)) {
            return 0;
        }

        $model = new $modelClass();
        $table = $modelClass::getTable();
        $connection = $model->getConnection();

        $data = self::normalizeRecords($records);
        $totalUpdated = 0;

        // Use transaction for bulk updates
        $connection->beginTransaction();

        try {
            foreach ($data as $row) {
                if (!isset($row[$keyColumn])) {
                    continue;
                }

                $keyValue = $row[$keyColumn];
                unset($row[$keyColumn]);

                if (empty($row)) {
                    continue;
                }

                $sets = [];
                $values = [];

                foreach ($row as $col => $value) {
                    $sets[] = "{$col} = ?";
                    $values[] = $value;
                }

                $values[] = $keyValue;

                $sql = sprintf(
                    "UPDATE %s SET %s WHERE %s = ?",
                    $table,
                    implode(', ', $sets),
                    $keyColumn
                );

                $connection->execute($sql, $values);
                $totalUpdated++;
            }

            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollback();
            throw $e;
        }

        return $totalUpdated;
    }

    /**
     * Bulk upsert (insert or update on duplicate key)
     * 
     * @param string $modelClass Model class name
     * @param array $records Array of records
     * @param string|array $uniqueColumns Column(s) to check for duplicates
     * @return int Number of affected records
     */
    public static function upsert(string $modelClass, array $records, string|array $uniqueColumns): int
    {
        if (empty($records)) {
            return 0;
        }

        $model = new $modelClass();
        $table = $modelClass::getTable();
        $connection = $model->getConnection();

        $data = self::normalizeRecords($records);
        
        if (empty($data)) {
            return 0;
        }

        $columns = array_keys($data[0]);
        $uniqueColumns = (array) $uniqueColumns;
        $updateColumns = array_diff($columns, $uniqueColumns);

        $totalAffected = 0;

        foreach (array_chunk($data, self::$chunkSize) as $chunk) {
            $placeholders = [];
            $values = [];

            foreach ($chunk as $row) {
                $rowPlaceholders = array_fill(0, count($columns), '?');
                $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
                
                foreach ($columns as $col) {
                    $values[] = $row[$col] ?? null;
                }
            }

            // Build ON DUPLICATE KEY UPDATE clause
            $updateParts = [];
            foreach ($updateColumns as $col) {
                $updateParts[] = "{$col} = VALUES({$col})";
            }

            $sql = sprintf(
                "INSERT INTO %s (%s) VALUES %s ON DUPLICATE KEY UPDATE %s",
                $table,
                implode(', ', $columns),
                implode(', ', $placeholders),
                implode(', ', $updateParts)
            );

            $result = $connection->execute($sql, $values);
            $totalAffected += count($chunk);
        }

        return $totalAffected;
    }

    /**
     * Bulk delete records
     * 
     * @param string $modelClass Model class name
     * @param array $ids Array of primary key values
     * @param string $keyColumn Primary key column name
     * @return int Number of deleted records
     */
    public static function delete(string $modelClass, array $ids, string $keyColumn = 'Id'): int
    {
        if (empty($ids)) {
            return 0;
        }

        $model = new $modelClass();
        $table = $modelClass::getTable();
        $connection = $model->getConnection();

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        
        $sql = "DELETE FROM {$table} WHERE {$keyColumn} IN ({$placeholders})";
        $result = $connection->execute($sql, $ids);

        return count($ids);
    }

    /**
     * Normalize records to array format
     */
    private static function normalizeRecords(array $records): array
    {
        $normalized = [];

        foreach ($records as $record) {
            if ($record instanceof Model) {
                $normalized[] = $record->toArray();
            } elseif (is_array($record)) {
                $normalized[] = $record;
            }
        }

        return $normalized;
    }
}
