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
use Miko\Database\ConnectionInterface;
use Miko\Database\Exceptions\DatabaseException;
use Miko\Database\Query\Grammar;

/**
 * Bulk Operations (no model events)
 *
 * BulkOperations::insert(User::class, $rows);
 * BulkOperations::update(User::class, $rows);            // key = model primary key
 * BulkOperations::upsert(User::class, $rows, 'Email');
 * BulkOperations::delete(User::class, [1, 2, 3]);         // soft delete aware
 *
 * Rows can be arrays or model instances (raw attributes are used, $hidden
 * columns included). Statements are chunked to the driver parameter limit
 * and run in one transaction.
 */
class BulkOperations
{
    private static int $chunkSize = 1000;

    /**
     * Maximum rows per statement (the driver parameter limit can lower it)
     */
    public static function setChunkSize(int $size): void
    {
        self::$chunkSize = max(1, $size);
    }

    /**
     * Bulk insert. Rows with different column sets are inserted separately,
     * so missing columns keep their database defaults.
     */
    public static function insert(string $modelClass, array $records): int
    {
        $model = self::model($modelClass);
        $rows = self::normalizeRecords($model, $records);
        if ($rows === []) {
            return 0;
        }

        $connection = $model->getConnection();
        $grammar = $connection->getGrammar();
        $table = $grammar->wrapTable($modelClass::getTable());

        return Transaction::run(function () use ($rows, $connection, $grammar, $table) {
            $total = 0;

            foreach (self::groupByColumns($rows) as $columns => $group) {
                $columns = explode(',', $columns);
                $wrapped = implode(', ', array_map(fn($c) => $grammar->wrap($c), $columns));
                $placeholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

                foreach (array_chunk($group, self::chunkSizeFor($connection, count($columns))) as $chunk) {
                    $values = [];
                    foreach ($chunk as $row) {
                        array_push($values, ...array_values($row));
                    }

                    $connection->execute(
                        "INSERT INTO {$table} ({$wrapped}) VALUES " . implode(', ', array_fill(0, count($chunk), $placeholder)),
                        $values
                    );
                    $total += count($chunk);
                }
            }

            return $total;
        }, $connection);
    }

    /**
     * Bulk update by key column (default: model primary key).
     * Rows with the same column set reuse one prepared statement.
     */
    public static function update(string $modelClass, array $records, ?string $keyColumn = null): int
    {
        $model = self::model($modelClass);
        $keyColumn = Grammar::assertReference($keyColumn ?? $model->getPrimaryKey());
        $rows = self::normalizeRecords($model, $records);
        if ($rows === []) {
            return 0;
        }

        $connection = $model->getConnection();
        $grammar = $connection->getGrammar();
        $table = $grammar->wrapTable($modelClass::getTable());

        return Transaction::run(function () use ($rows, $keyColumn, $connection, $grammar, $table) {
            $statements = [];
            $updated = 0;

            foreach ($rows as $row) {
                if (!array_key_exists($keyColumn, $row) || $row[$keyColumn] === null) {
                    continue;
                }

                $key = $row[$keyColumn];
                unset($row[$keyColumn]);
                if ($row === []) {
                    continue;
                }

                $signature = implode(',', array_keys($row));
                if (!isset($statements[$signature])) {
                    $sets = array_map(fn($c) => $grammar->wrap($c) . ' = ?', array_keys($row));
                    $statements[$signature] = $connection->prepare(
                        "UPDATE {$table} SET " . implode(', ', $sets) . ' WHERE ' . $grammar->wrap($keyColumn) . ' = ?'
                    );
                }

                $statement = $statements[$signature];
                $position = 1;
                foreach ($row as $value) {
                    $statement->bindValue($position++, $value);
                }
                $statement->bindValue($position, $key);
                $statement->execute();
                $updated += $statement->rowCount();
            }

            return $updated;
        }, $connection);
    }

    /**
     * Insert or update on unique column(s).
     * MySQL/MariaDB: ON DUPLICATE KEY UPDATE, PostgreSQL/SQLite: ON CONFLICT, SQL Server: MERGE.
     *
     * @param string|array $uniqueColumns columns of the unique index
     * @param array|null $updateColumns columns to update on conflict (default: all others)
     */
    public static function upsert(string $modelClass, array $records, string|array $uniqueColumns, ?array $updateColumns = null): int
    {
        $model = self::model($modelClass);
        $rows = self::normalizeRecords($model, $records);
        if ($rows === []) {
            return 0;
        }

        $connection = $model->getConnection();
        $grammar = $connection->getGrammar();
        $driver = $connection->getDriverName();
        $table = $grammar->wrapTable($modelClass::getTable());
        $uniqueColumns = array_map(fn($c) => Grammar::assertReference($c), (array) $uniqueColumns);

        return Transaction::run(function () use ($rows, $connection, $grammar, $driver, $table, $uniqueColumns, $updateColumns) {
            $total = 0;

            foreach (self::groupByColumns($rows) as $columns => $group) {
                $columns = explode(',', $columns);
                $update = $updateColumns ?? array_values(array_diff($columns, $uniqueColumns));
                $wrapped = implode(', ', array_map(fn($c) => $grammar->wrap($c), $columns));
                $placeholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

                if ($driver === 'sqlsrv') {
                    $total += self::mergeSqlServer($connection, $grammar, $table, $columns, $uniqueColumns, $update, $group);
                    continue;
                }

                if ($driver === 'mysql') {
                    $clause = $update === []
                        ? ' ON DUPLICATE KEY UPDATE ' . $grammar->wrap($uniqueColumns[0]) . ' = ' . $grammar->wrap($uniqueColumns[0])
                        : ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(
                            fn($c) => $grammar->wrap($c) . ' = VALUES(' . $grammar->wrap($c) . ')',
                            $update
                        ));
                } else {
                    $target = '(' . implode(', ', array_map(fn($c) => $grammar->wrap($c), $uniqueColumns)) . ')';
                    $clause = $update === []
                        ? " ON CONFLICT {$target} DO NOTHING"
                        : " ON CONFLICT {$target} DO UPDATE SET " . implode(', ', array_map(
                            fn($c) => $grammar->wrap($c) . ' = excluded.' . $grammar->wrap($c),
                            $update
                        ));
                }

                foreach (array_chunk($group, self::chunkSizeFor($connection, count($columns))) as $chunk) {
                    $values = [];
                    foreach ($chunk as $row) {
                        array_push($values, ...array_values($row));
                    }

                    $connection->execute(
                        "INSERT INTO {$table} ({$wrapped}) VALUES " . implode(', ', array_fill(0, count($chunk), $placeholder)) . $clause,
                        $values
                    );
                    $total += count($chunk);
                }
            }

            return $total;
        }, $connection);
    }

    /**
     * SQL Server upsert: MERGE ... WITH (HOLDLOCK) (the lock keeps two concurrent upserts from inserting the same key)
     */
    private static function mergeSqlServer(
        ConnectionInterface $connection,
        Grammar $grammar,
        string $table,
        array $columns,
        array $uniqueColumns,
        array $update,
        array $rows
    ): int {
        $source = implode(', ', array_map(fn($c) => $grammar->quote($c), $columns));
        $on = implode(' AND ', array_map(
            fn($c) => 'miko_t.' . $grammar->quote(self::column($c)) . ' = miko_s.' . $grammar->quote(self::column($c)),
            $uniqueColumns
        ));
        $set = implode(', ', array_map(
            fn($c) => 'miko_t.' . $grammar->quote(self::column($c)) . ' = miko_s.' . $grammar->quote(self::column($c)),
            $update
        ));
        $insertValues = implode(', ', array_map(fn($c) => 'miko_s.' . $grammar->quote($c), $columns));
        $placeholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $total = 0;

        foreach (array_chunk($rows, self::chunkSizeFor($connection, count($columns))) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                array_push($values, ...array_values($row));
            }

            $sql = "MERGE INTO {$table} WITH (HOLDLOCK) AS miko_t"
                . ' USING (VALUES ' . implode(', ', array_fill(0, count($chunk), $placeholder)) . ") AS miko_s ({$source})"
                . " ON {$on}"
                . ($set !== '' ? " WHEN MATCHED THEN UPDATE SET {$set}" : '')
                . " WHEN NOT MATCHED THEN INSERT ({$source}) VALUES ({$insertValues});";

            $connection->execute($sql, $values);
            $total += count($chunk);
        }

        return $total;
    }

    /**
     * "tbl.Col" -> "Col"
     */
    private static function column(string $reference): string
    {
        $parts = explode('.', $reference);
        return end($parts);
    }

    /**
     * Delete by key values. Soft-delete models get DeletedAt set.
     * Returns the number of affected rows.
     */
    public static function delete(string $modelClass, array $ids, ?string $keyColumn = null): int
    {
        $ids = array_values($ids);
        if ($ids === []) {
            return 0;
        }

        $model = self::model($modelClass);
        $keyColumn = Grammar::assertReference($keyColumn ?? $model->getPrimaryKey());
        $connection = $model->getConnection();
        $grammar = $connection->getGrammar();
        $table = $grammar->wrapTable($modelClass::getTable());
        $soft = $model->usesSoftDeletes();

        return Transaction::run(function () use ($ids, $keyColumn, $connection, $grammar, $table, $soft, $model) {
            $affected = 0;

            foreach (array_chunk($ids, self::chunkSizeFor($connection, 1) - 2) as $chunk) {
                $in = $grammar->wrap($keyColumn) . ' IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')';

                if ($soft) {
                    $sql = "UPDATE {$table} SET " . $grammar->wrap($model::getDeletedAtColumn()) . " = ? WHERE {$in}";
                    $bindings = array_merge([$model->freshTimestampString()], $chunk);
                } else {
                    $sql = "DELETE FROM {$table} WHERE {$in}";
                    $bindings = $chunk;
                }

                $affected += $connection->execute($sql, $bindings)->count();
            }

            return $affected;
        }, $connection);
    }

    // ========================================
    // Helpers
    // ========================================

    private static function model(string $modelClass): Model
    {
        if (!is_subclass_of($modelClass, Model::class)) {
            throw new DatabaseException("{$modelClass} must extend " . Model::class);
        }
        return new $modelClass();
    }

    /**
     * Arrays and models to database-ready rows (casts applied, columns validated)
     */
    private static function normalizeRecords(Model $model, array $records): array
    {
        $rows = [];

        foreach ($records as $record) {
            if ($record instanceof Model) {
                $row = $record->getAttributesForDatabase();
            } elseif (is_array($record)) {
                $row = [];
                foreach ($record as $column => $value) {
                    $row[$column] = $model->toDatabaseValue((string) $column, $value);
                }
            } else {
                continue;
            }

            foreach (array_keys($row) as $column) {
                Grammar::assertReference((string) $column);
            }

            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, array[]> "ColA,ColB" => rows
     */
    private static function groupByColumns(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[implode(',', array_keys($row))][] = $row;
        }
        return $groups;
    }

    private static function chunkSizeFor(ConnectionInterface $connection, int $columns): int
    {
        $maxParameters = $connection instanceof Connection
            ? $connection->maxParameters()
            : $connection->getGrammar()->maxParameters();

        return max(1, min(self::$chunkSize, intdiv($maxParameters, max(1, $columns))));
    }
}
