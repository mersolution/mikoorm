<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\Migration;

use Miko\Database\ConnectionInterface;
use Miko\Database\Exceptions\DatabaseException;
use Miko\Database\Query\Grammar;

/**
 * Schema - DDL operations for mysql, pgsql, sqlite and sqlsrv
 */
class Schema
{
    private ConnectionInterface $connection;
    private Grammar $grammar;
    private string $driver;

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
        $this->grammar = $connection->getGrammar();
        $this->driver = $connection->getDriverName();
    }

    private function exec(string $sql): void
    {
        $this->connection->getPdo()->exec($sql);
    }

    private function quoteTable(string $name): string
    {
        return $this->grammar->wrapTable($name);
    }

    private function column(string $name): string
    {
        return $this->grammar->quote(Grammar::assertIdentifier($name, 'column name'));
    }

    /**
     * Create a new table
     */
    public function createTable(string $tableName, callable $callback): void
    {
        $builder = new TableBuilder($tableName, $this->driver);
        $callback($builder);

        $this->exec($builder->build());

        foreach ($builder->getIndexStatements() as $indexSql) {
            $this->exec($indexSql);
        }
    }

    public function create(string $tableName, callable $callback): void
    {
        $this->createTable($tableName, $callback);
    }

    /**
     * Add columns / indexes / foreign keys to an existing table
     */
    public function table(string $tableName, callable $callback): void
    {
        $builder = new TableBuilder($tableName, $this->driver);
        $callback($builder);
        $table = $this->quoteTable($tableName);
        $add = $this->driver === 'sqlsrv' ? ' ADD ' : ' ADD COLUMN ';

        foreach ($builder->getColumns() as $column) {
            $this->exec("ALTER TABLE {$table}{$add}" . $column->build($this->driver));
        }

        foreach ($builder->getUniqueConstraints() as $unique) {
            if (!$builder->isFilteredUnique($unique)) { // filtered ones come with getIndexStatements()
                $this->createUniqueIndex($tableName, $unique['name'], $unique['columns']);
            }
        }

        foreach ($builder->getIndexStatements() as $indexSql) {
            $this->exec($indexSql);
        }

        foreach ($builder->getForeignKeys() as $fk) {
            if ($this->driver === 'sqlite') {
                throw new DatabaseException('SQLite cannot add foreign keys to an existing table.');
            }
            $this->exec("ALTER TABLE {$table} ADD " . $fk->build($this->driver));
        }
    }

    public function dropTable(string $tableName): void
    {
        $this->exec('DROP TABLE ' . $this->quoteTable($tableName));
    }

    public function dropTableIfExists(string $tableName): void
    {
        $this->exec('DROP TABLE IF EXISTS ' . $this->quoteTable($tableName));
    }

    public function dropIfExists(string $tableName): void
    {
        $this->dropTableIfExists($tableName);
    }

    public function renameTable(string $oldName, string $newName): void
    {
        $old = $this->quoteTable($oldName);
        $new = $this->quoteTable($newName);

        match ($this->driver) {
            'mysql' => $this->exec("RENAME TABLE {$old} TO {$new}"),
            'sqlsrv' => $this->connection->execute('EXEC sp_rename ?, ?', [Grammar::assertTable($oldName), Grammar::assertIdentifier($newName, 'table name')]),
            default => $this->exec("ALTER TABLE {$old} RENAME TO " . $this->grammar->quote(Grammar::assertIdentifier($newName, 'table name'))),
        };
    }

    public function hasTable(string $tableName): bool
    {
        Grammar::assertTable($tableName);
        $parts = explode('.', $tableName);
        $name = end($parts);
        $schema = count($parts) > 1 ? $parts[0] : null;

        [$sql, $bindings] = match ($this->driver) {
            'mysql' => $schema === null
                ? ['SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$name]]
                : ['SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ?', [$schema, $name]],
            'pgsql' => $schema === null
                ? ['SELECT 1 FROM information_schema.tables WHERE table_schema = ANY (current_schemas(false)) AND table_name = ?', [$name]]
                : ['SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ?', [$schema, $name]],
            'sqlite' => ["SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? COLLATE NOCASE", [$name]],
            'sqlsrv' => $schema === null
                ? ['SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = ?', [$name]]
                : ['SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$schema, $name]],
            default => throw new DatabaseException("Unsupported driver: {$this->driver}"),
        };

        return $this->connection->execute($sql, $bindings)->first() !== null;
    }

    public function hasColumn(string $tableName, string $columnName): bool
    {
        Grammar::assertTable($tableName);
        Grammar::assertIdentifier($columnName, 'column name');
        $parts = explode('.', $tableName);
        $name = end($parts);

        if ($this->driver === 'sqlite') {
            foreach ($this->connection->execute('PRAGMA table_info(' . $this->quoteTable($tableName) . ')')->all() as $row) {
                if (strcasecmp((string) $row['name'], $columnName) === 0) {
                    return true;
                }
            }
            return false;
        }

        $sql = match ($this->driver) {
            'mysql' => 'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            'pgsql' => 'SELECT 1 FROM information_schema.columns WHERE table_schema = ANY (current_schemas(false)) AND table_name = ? AND column_name = ?',
            default => 'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND COLUMN_NAME = ?',
        };

        return $this->connection->execute($sql, [$name, $columnName])->first() !== null;
    }

    /**
     * Add a column with a raw type: addColumn('Users', 'Age', 'INT', ['nullable' => false, 'default' => 0])
     */
    public function addColumn(string $tableName, string $columnName, string $type, array $options = []): void
    {
        $this->assertType($type);
        $sql = 'ALTER TABLE ' . $this->quoteTable($tableName) . ($this->driver === 'sqlsrv' ? ' ADD ' : ' ADD COLUMN ')
            . $this->column($columnName) . ' ' . $type;

        if (!($options['nullable'] ?? true)) {
            $sql .= ' NOT NULL';
        }

        if (array_key_exists('default', $options)) {
            $sql .= ' DEFAULT ' . $this->literal($options['default']);
        }

        if (isset($options['after']) && $this->driver === 'mysql') {
            $sql .= ' AFTER ' . $this->column($options['after']);
        }

        $this->exec($sql);
    }

    public function dropColumn(string $tableName, string $columnName): void
    {
        $this->exec('ALTER TABLE ' . $this->quoteTable($tableName) . ' DROP COLUMN ' . $this->column($columnName));
    }

    /**
     * Rename a column ($type is only needed for old MySQL versions without RENAME COLUMN)
     */
    public function renameColumn(string $tableName, string $oldName, string $newName, ?string $type = null): void
    {
        $table = $this->quoteTable($tableName);

        if ($this->driver === 'sqlsrv') {
            $this->connection->execute('EXEC sp_rename ?, ?, ?', [Grammar::assertTable($tableName) . '.' . Grammar::assertIdentifier($oldName, 'column name'), Grammar::assertIdentifier($newName, 'column name'), 'COLUMN']);
            return;
        }

        if ($this->driver === 'mysql' && $type !== null) {
            $this->assertType($type);
            $this->exec("ALTER TABLE {$table} CHANGE " . $this->column($oldName) . ' ' . $this->column($newName) . ' ' . $type);
            return;
        }

        $this->exec("ALTER TABLE {$table} RENAME COLUMN " . $this->column($oldName) . ' TO ' . $this->column($newName));
    }

    /**
     * Change a column type (not supported by SQLite)
     */
    public function modifyColumn(string $tableName, string $columnName, string $type, array $options = []): void
    {
        $this->assertType($type);
        $table = $this->quoteTable($tableName);
        $column = $this->column($columnName);
        $nullable = $options['nullable'] ?? true;

        switch ($this->driver) {
            case 'mysql':
                $sql = "ALTER TABLE {$table} MODIFY COLUMN {$column} {$type}" . ($nullable ? ' NULL' : ' NOT NULL');
                if (array_key_exists('default', $options)) {
                    $sql .= ' DEFAULT ' . $this->literal($options['default']);
                }
                $this->exec($sql);
                return;

            case 'pgsql':
                $this->exec("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE {$type}");
                $this->exec("ALTER TABLE {$table} ALTER COLUMN {$column} " . ($nullable ? 'DROP NOT NULL' : 'SET NOT NULL'));
                if (array_key_exists('default', $options)) {
                    $this->exec("ALTER TABLE {$table} ALTER COLUMN {$column} SET DEFAULT " . $this->literal($options['default']));
                }
                return;

            case 'sqlsrv':
                $this->exec("ALTER TABLE {$table} ALTER COLUMN {$column} {$type}" . ($nullable ? ' NULL' : ' NOT NULL'));
                return;
        }

        throw new DatabaseException('modifyColumn() is not supported on SQLite; recreate the table instead.');
    }

    public function createIndex(string $tableName, string $indexName, array $columns): void
    {
        $this->exec('CREATE INDEX ' . $this->grammar->quote(Grammar::assertIdentifier($indexName, 'index name'))
            . ' ON ' . $this->quoteTable($tableName) . ' (' . implode(', ', array_map(fn($c) => $this->column($c), $columns)) . ')');
    }

    public function createUniqueIndex(string $tableName, string $indexName, array $columns): void
    {
        $this->exec('CREATE UNIQUE INDEX ' . $this->grammar->quote(Grammar::assertIdentifier($indexName, 'index name'))
            . ' ON ' . $this->quoteTable($tableName) . ' (' . implode(', ', array_map(fn($c) => $this->column($c), $columns)) . ')');
    }

    public function dropIndex(string $tableName, string $indexName): void
    {
        $index = $this->grammar->quote(Grammar::assertIdentifier($indexName, 'index name'));

        if ($this->driver === 'mysql' || $this->driver === 'sqlsrv') {
            $this->exec("DROP INDEX {$index} ON " . $this->quoteTable($tableName));
        } else {
            $this->exec("DROP INDEX {$index}");
        }
    }

    public function addForeignKey(string $tableName, string $column, string $referencesTable, string $referencesColumn = 'Id', string $onDelete = 'CASCADE'): void
    {
        if ($this->driver === 'sqlite') {
            throw new DatabaseException('SQLite cannot add foreign keys to an existing table.');
        }

        $fk = (new TableBuilder($tableName, $this->driver))->foreign($column)->references($referencesColumn)->on($referencesTable)->onDelete($onDelete);
        $this->exec('ALTER TABLE ' . $this->quoteTable($tableName) . ' ADD ' . $fk->build($this->driver));
    }

    public function dropForeignKey(string $tableName, string $fkName): void
    {
        $name = $this->grammar->quote(Grammar::assertIdentifier($fkName, 'constraint name'));

        match ($this->driver) {
            'mysql' => $this->exec('ALTER TABLE ' . $this->quoteTable($tableName) . " DROP FOREIGN KEY {$name}"),
            'sqlite' => throw new DatabaseException('SQLite cannot drop foreign keys from an existing table.'),
            default => $this->exec('ALTER TABLE ' . $this->quoteTable($tableName) . " DROP CONSTRAINT {$name}"),
        };
    }

    /**
     * Execute raw DDL
     */
    public function raw(string $sql): void
    {
        $this->exec($sql);
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    private function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $this->grammar->booleanLiteral($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value) && strtoupper($value) === 'CURRENT_TIMESTAMP') {
            return 'CURRENT_TIMESTAMP';
        }
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    /**
     * Raw column types: letters, digits, spaces, commas and parentheses only
     */
    private function assertType(string $type): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_ ]*(\(\s*[0-9A-Za-z, ]+\s*\))?( [A-Za-z ]+)?$/', trim($type))) {
            throw new DatabaseException("Invalid column type: {$type}");
        }
    }
}
