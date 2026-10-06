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

use Miko\Database\Exceptions\DatabaseException;
use Miko\Database\Query\Grammar;

/**
 * TableBuilder - Fluent API for table schemas.
 * Builds CREATE TABLE for mysql, pgsql, sqlite and sqlsrv.
 */
class TableBuilder
{
    private string $tableName;
    private string $driver;
    /** @var ColumnBuilder[] */
    private array $columns = [];
    private array $primaryKeys = [];
    private array $indexes = [];
    /** @var ForeignKeyBuilder[] */
    private array $foreignKeys = [];
    private array $uniqueConstraints = [];

    public function __construct(string $tableName, string $driver = 'mysql')
    {
        $this->tableName = Grammar::assertTable($tableName);
        $this->driver = strtolower($driver);
    }

    private function add(string $name, string $type): ColumnBuilder
    {
        $column = new ColumnBuilder(Grammar::assertIdentifier($name, 'column name'), $type, $this);
        $this->columns[] = $column;
        return $column;
    }

    /**
     * Auto-incrementing INT primary key
     */
    public function id(string $name = 'Id'): self
    {
        $this->add($name, 'INT')->unsigned()->autoIncrement()->notNull();
        $this->primaryKeys[] = $name;
        return $this;
    }

    /**
     * Auto-incrementing BIGINT primary key
     */
    public function bigId(string $name = 'Id'): self
    {
        $this->add($name, 'BIGINT')->unsigned()->autoIncrement()->notNull();
        $this->primaryKeys[] = $name;
        return $this;
    }

    /**
     * UUID primary key (VARCHAR(36))
     */
    public function uuid(string $name = 'Id'): ColumnBuilder
    {
        $column = $this->add($name, 'VARCHAR')->length(36)->notNull();
        $this->primaryKeys[] = $name;
        return $column;
    }

    public function string(string $name, int $length = 255): ColumnBuilder
    {
        return $this->add($name, 'VARCHAR')->length($length);
    }

    public function char(string $name, int $length = 1): ColumnBuilder
    {
        return $this->add($name, 'CHAR')->length($length);
    }

    public function text(string $name): ColumnBuilder
    {
        return $this->add($name, 'TEXT');
    }

    public function mediumText(string $name): ColumnBuilder
    {
        return $this->add($name, 'MEDIUMTEXT');
    }

    public function longText(string $name): ColumnBuilder
    {
        return $this->add($name, 'LONGTEXT');
    }

    public function integer(string $name): ColumnBuilder
    {
        return $this->add($name, 'INT');
    }

    public function bigInteger(string $name): ColumnBuilder
    {
        return $this->add($name, 'BIGINT');
    }

    public function smallInteger(string $name): ColumnBuilder
    {
        return $this->add($name, 'SMALLINT');
    }

    public function tinyInteger(string $name): ColumnBuilder
    {
        return $this->add($name, 'TINYINT');
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnBuilder
    {
        return $this->add($name, 'DECIMAL')->precision($precision, $scale);
    }

    public function float(string $name): ColumnBuilder
    {
        return $this->add($name, 'FLOAT');
    }

    public function double(string $name): ColumnBuilder
    {
        return $this->add($name, 'DOUBLE');
    }

    public function boolean(string $name): ColumnBuilder
    {
        return $this->add($name, 'BOOLEAN');
    }

    public function date(string $name): ColumnBuilder
    {
        return $this->add($name, 'DATE');
    }

    public function dateTime(string $name): ColumnBuilder
    {
        return $this->add($name, 'DATETIME');
    }

    public function timestamp(string $name): ColumnBuilder
    {
        return $this->add($name, 'TIMESTAMP');
    }

    public function time(string $name): ColumnBuilder
    {
        return $this->add($name, 'TIME');
    }

    public function json(string $name): ColumnBuilder
    {
        return $this->add($name, 'JSON');
    }

    public function binary(string $name): ColumnBuilder
    {
        return $this->add($name, 'BLOB');
    }

    public function enum(string $name, array $values): ColumnBuilder
    {
        return $this->add($name, 'ENUM')->allowed($values);
    }

    /**
     * CreatedDate and UpdatedDate (nullable DATETIME)
     */
    public function timestamps(): self
    {
        $this->dateTime('CreatedDate')->nullable();
        $this->dateTime('UpdatedDate')->nullable();
        return $this;
    }

    /**
     * Soft delete column (default DeletedAt)
     */
    public function softDeletes(string $name = 'DeletedAt'): self
    {
        $this->dateTime($name)->nullable();
        return $this;
    }

    /**
     * Foreign key column (unsigned INT, matches id())
     */
    public function foreignId(string $name): ColumnBuilder
    {
        return $this->add($name, 'INT')->unsigned();
    }

    public function foreign(string $column): ForeignKeyBuilder
    {
        $fk = new ForeignKeyBuilder(Grammar::assertIdentifier($column, 'column name'), $this);
        $this->foreignKeys[] = $fk;
        return $fk;
    }

    public function primary(string|array $columns): self
    {
        foreach ((array) $columns as $column) {
            $this->primaryKeys[] = Grammar::assertIdentifier($column, 'column name');
        }
        $this->primaryKeys = array_values(array_unique($this->primaryKeys));
        return $this;
    }

    public function index(string|array $columns, ?string $name = null): self
    {
        $columns = array_map(fn($c) => Grammar::assertIdentifier($c, 'column name'), (array) $columns);
        $this->indexes[] = [
            'name' => Grammar::assertIdentifier($name ?? $this->indexName('idx', $columns), 'index name'),
            'columns' => $columns,
        ];
        return $this;
    }

    public function unique(string|array $columns, ?string $name = null): self
    {
        $columns = array_map(fn($c) => Grammar::assertIdentifier($c, 'column name'), (array) $columns);
        $this->uniqueConstraints[] = [
            'name' => Grammar::assertIdentifier($name ?? $this->indexName('uq', $columns), 'index name'),
            'columns' => $columns,
        ];
        return $this;
    }

    private function indexName(string $prefix, array $columns): string
    {
        $name = $prefix . '_' . str_replace('.', '_', $this->tableName) . '_' . implode('_', $columns);
        // Identifier limits: MySQL 64, PostgreSQL 63
        return strlen($name) > 60 ? substr($name, 0, 51) . '_' . substr(md5($name), 0, 8) : $name;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function getGrammar(): Grammar
    {
        return Grammar::for($this->driver);
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * @return ColumnBuilder[]
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getPrimaryKeys(): array
    {
        return $this->primaryKeys;
    }

    /**
     * @return ForeignKeyBuilder[]
     */
    public function getForeignKeys(): array
    {
        return $this->foreignKeys;
    }

    public function getUniqueConstraints(): array
    {
        return $this->uniqueConstraints;
    }

    /**
     * CREATE TABLE statement
     */
    public function build(): string
    {
        $grammar = $this->getGrammar();
        $definitions = [];

        // SQLite needs the autoincrement key inline
        $inlinePrimary = null;
        if ($this->driver === 'sqlite' && count($this->primaryKeys) === 1) {
            foreach ($this->columns as $column) {
                if ($column->getName() === $this->primaryKeys[0] && $column->isAutoIncrement()) {
                    $inlinePrimary = $column->getName();
                }
            }
        }

        foreach ($this->columns as $column) {
            $definitions[] = $column->build($this->driver, $column->getName() === $inlinePrimary);
        }

        if ($this->primaryKeys !== [] && $inlinePrimary === null) {
            $definitions[] = 'PRIMARY KEY (' . implode(', ', array_map(fn($c) => $grammar->quote($c), $this->primaryKeys)) . ')';
        }

        foreach ($this->uniqueConstraints as $unique) {
            if ($this->isFilteredUnique($unique)) {
                continue; // filtered unique index, see getIndexStatements()
            }
            $definitions[] = 'CONSTRAINT ' . $grammar->quote($unique['name']) . ' UNIQUE ('
                . implode(', ', array_map(fn($c) => $grammar->quote($c), $unique['columns'])) . ')';
        }

        foreach ($this->foreignKeys as $fk) {
            $definitions[] = $fk->build($this->driver);
        }

        $sql = 'CREATE TABLE ' . $grammar->wrapTable($this->tableName) . " (\n    " . implode(",\n    ", $definitions) . "\n)";

        if ($this->driver === 'mysql') {
            $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }

        return $sql;
    }

    /**
     * CREATE INDEX statements (run after CREATE TABLE)
     */
    public function getIndexStatements(): array
    {
        $grammar = $this->getGrammar();
        $statements = [];

        foreach ($this->indexes as $index) {
            $statements[] = 'CREATE INDEX ' . $grammar->quote($index['name']) . ' ON ' . $grammar->wrapTable($this->tableName)
                . ' (' . implode(', ', array_map(fn($c) => $grammar->quote($c), $index['columns'])) . ')';
        }

        foreach ($this->uniqueConstraints as $unique) {
            if ($this->isFilteredUnique($unique)) {
                $statements[] = 'CREATE UNIQUE INDEX ' . $grammar->quote($unique['name']) . ' ON ' . $grammar->wrapTable($this->tableName)
                    . ' (' . implode(', ', array_map(fn($c) => $grammar->quote($c), $unique['columns'])) . ')'
                    . ' WHERE ' . implode(' AND ', array_map(fn($c) => $grammar->quote($c) . ' IS NOT NULL', $unique['columns']));
            }
        }

        return $statements;
    }

    /**
     * SQL Server allows only one NULL in a UNIQUE constraint; MySQL, PostgreSQL and SQLite
     * allow many. Unique keys on nullable columns become a filtered unique index there
     * (WHERE col IS NOT NULL), so every driver behaves the same.
     */
    public function isFilteredUnique(array $unique): bool
    {
        if ($this->driver !== 'sqlsrv') {
            return false;
        }

        foreach ($this->columns as $column) {
            if (in_array($column->getName(), $unique['columns'], true) && $column->isNullable()) {
                return true;
            }
        }
        return false;
    }
}

/**
 * ColumnBuilder - Fluent column definition
 */
class ColumnBuilder
{
    private string $name;
    private string $type;
    private TableBuilder $tableBuilder;
    private bool $nullable = true;
    private bool $unsigned = false;
    private bool $autoIncrement = false;
    private ?int $length = null;
    private ?int $precision = null;
    private ?int $scale = null;
    private mixed $default = null;
    private bool $hasDefault = false;
    private bool $useCurrent = false;
    private array $allowed = [];

    public function __construct(string $name, string $type, TableBuilder $tableBuilder)
    {
        $this->name = $name;
        $this->type = $type;
        $this->tableBuilder = $tableBuilder;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isAutoIncrement(): bool
    {
        return $this->autoIncrement;
    }

    public function isNullable(): bool
    {
        return $this->nullable && !$this->autoIncrement;
    }

    public function nullable(): self
    {
        $this->nullable = true;
        return $this;
    }

    public function notNull(): self
    {
        $this->nullable = false;
        return $this;
    }

    public function unsigned(): self
    {
        $this->unsigned = true;
        return $this;
    }

    public function autoIncrement(): self
    {
        $this->autoIncrement = true;
        return $this;
    }

    public function length(int $length): self
    {
        $this->length = $length;
        return $this;
    }

    public function precision(int $precision, int $scale = 0): self
    {
        $this->precision = $precision;
        $this->scale = $scale;
        return $this;
    }

    public function default(mixed $value): self
    {
        $this->default = $value;
        $this->hasDefault = true;
        return $this;
    }

    /**
     * DEFAULT CURRENT_TIMESTAMP
     */
    public function useCurrent(): self
    {
        $this->useCurrent = true;
        return $this;
    }

    public function allowed(array $values): self
    {
        $this->allowed = array_values($values);
        return $this;
    }

    public function unique(): self
    {
        $this->tableBuilder->unique($this->name);
        return $this;
    }

    public function index(): self
    {
        $this->tableBuilder->index($this->name);
        return $this;
    }

    public function primary(): self
    {
        $this->tableBuilder->primary($this->name);
        return $this;
    }

    public function references(string $column): ForeignKeyBuilder
    {
        return $this->tableBuilder->foreign($this->name)->references($column);
    }

    /**
     * Column definition for a driver
     */
    public function build(string $driver = 'mysql', bool $inlinePrimaryKey = false): string
    {
        $grammar = Grammar::for($driver);
        $sql = $grammar->quote($this->name) . ' ';

        // SQLite autoincrement primary key: INTEGER PRIMARY KEY AUTOINCREMENT
        if ($inlinePrimaryKey) {
            return $sql . 'INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        // PostgreSQL serial types
        if ($driver === 'pgsql' && $this->autoIncrement) {
            return $sql . ($this->type === 'BIGINT' ? 'BIGSERIAL' : 'SERIAL') . ' NOT NULL';
        }

        $sql .= $this->buildType($driver);

        if ($this->unsigned && $driver === 'mysql' && in_array($this->type, ['INT', 'BIGINT', 'SMALLINT', 'TINYINT', 'DECIMAL', 'FLOAT', 'DOUBLE'], true)) {
            $sql .= ' UNSIGNED';
        }

        $sql .= $this->nullable ? ' NULL' : ' NOT NULL';

        if ($this->autoIncrement) {
            $sql .= match ($driver) {
                'mysql' => ' AUTO_INCREMENT',
                'sqlsrv' => ' IDENTITY(1,1)',
                default => '',
            };
        }

        if ($this->useCurrent) {
            $sql .= ' DEFAULT CURRENT_TIMESTAMP';
        } elseif ($this->hasDefault) {
            $sql .= ' DEFAULT ' . $this->defaultLiteral($grammar);
        }

        if ($this->type === 'ENUM' && $driver !== 'mysql') {
            $sql .= ' CHECK (' . $grammar->quote($this->name) . ' IN (' . $this->allowedList() . '))';
        }

        return $sql;
    }

    private function buildType(string $driver): string
    {
        $length = $this->length ?? 255;
        $precision = $this->precision ?? 10;
        $scale = $this->scale ?? 2;

        return match ($driver) {
            'pgsql' => match ($this->type) {
                'INT' => 'INTEGER',
                'TINYINT' => 'SMALLINT',
                'VARCHAR' => "VARCHAR({$length})",
                'CHAR' => 'CHAR(' . ($this->length ?? 1) . ')',
                'TEXT', 'MEDIUMTEXT', 'LONGTEXT' => 'TEXT',
                'DECIMAL' => "NUMERIC({$precision},{$scale})",
                'FLOAT' => 'REAL',
                'DOUBLE' => 'DOUBLE PRECISION',
                'BOOLEAN' => 'BOOLEAN',
                'DATETIME', 'TIMESTAMP' => 'TIMESTAMP(0) WITHOUT TIME ZONE',
                'JSON' => 'JSONB',
                'BLOB' => 'BYTEA',
                'ENUM' => 'VARCHAR(255)',
                default => $this->type,
            },
            'sqlite' => match ($this->type) {
                'INT', 'BIGINT', 'SMALLINT', 'TINYINT', 'BOOLEAN' => 'INTEGER',
                'VARCHAR' => "VARCHAR({$length})",
                'CHAR' => 'CHAR(' . ($this->length ?? 1) . ')',
                'TEXT', 'MEDIUMTEXT', 'LONGTEXT', 'JSON', 'ENUM' => 'TEXT',
                'DECIMAL' => "NUMERIC({$precision},{$scale})",
                'FLOAT', 'DOUBLE' => 'REAL',
                default => $this->type,
            },
            'sqlsrv' => match ($this->type) {
                'VARCHAR' => "NVARCHAR({$length})",
                'CHAR' => 'NCHAR(' . ($this->length ?? 1) . ')',
                'TEXT', 'MEDIUMTEXT', 'LONGTEXT', 'JSON' => 'NVARCHAR(MAX)',
                'DECIMAL' => "DECIMAL({$precision},{$scale})",
                'FLOAT' => 'REAL',
                'DOUBLE' => 'FLOAT',
                'BOOLEAN' => 'BIT',
                'DATETIME', 'TIMESTAMP' => 'DATETIME2(0)',
                'BLOB' => 'VARBINARY(MAX)',
                'ENUM' => 'NVARCHAR(255)',
                default => $this->type,
            },
            default => match ($this->type) {
                'VARCHAR' => "VARCHAR({$length})",
                'CHAR' => 'CHAR(' . ($this->length ?? 1) . ')',
                'DECIMAL' => "DECIMAL({$precision},{$scale})",
                'BOOLEAN' => 'TINYINT(1)',
                'TINYINT' => $this->length ? "TINYINT({$this->length})" : 'TINYINT',
                'ENUM' => 'ENUM(' . $this->allowedList() . ')',
                default => $this->type,
            },
        };
    }

    private function defaultLiteral(Grammar $grammar): string
    {
        if ($this->default === null) {
            return 'NULL';
        }
        if (is_bool($this->default)) {
            return $grammar->booleanLiteral($this->default);
        }
        if (is_int($this->default) || is_float($this->default)) {
            return (string) $this->default;
        }
        return "'" . str_replace("'", "''", (string) $this->default) . "'";
    }

    private function allowedList(): string
    {
        return implode(', ', array_map(fn($v) => "'" . str_replace("'", "''", (string) $v) . "'", $this->allowed));
    }

    // Chainable methods back to TableBuilder
    public function id(string $name = 'Id'): TableBuilder { return $this->tableBuilder->id($name); }
    public function string(string $name, int $length = 255): ColumnBuilder { return $this->tableBuilder->string($name, $length); }
    public function text(string $name): ColumnBuilder { return $this->tableBuilder->text($name); }
    public function integer(string $name): ColumnBuilder { return $this->tableBuilder->integer($name); }
    public function bigInteger(string $name): ColumnBuilder { return $this->tableBuilder->bigInteger($name); }
    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnBuilder { return $this->tableBuilder->decimal($name, $precision, $scale); }
    public function boolean(string $name): ColumnBuilder { return $this->tableBuilder->boolean($name); }
    public function dateTime(string $name): ColumnBuilder { return $this->tableBuilder->dateTime($name); }
    public function date(string $name): ColumnBuilder { return $this->tableBuilder->date($name); }
    public function foreignId(string $name): ColumnBuilder { return $this->tableBuilder->foreignId($name); }
    public function timestamps(): TableBuilder { return $this->tableBuilder->timestamps(); }
    public function softDeletes(string $name = 'DeletedAt'): TableBuilder { return $this->tableBuilder->softDeletes($name); }
}

/**
 * ForeignKeyBuilder - Fluent foreign key definition
 */
class ForeignKeyBuilder
{
    private const ACTIONS = ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION', 'SET DEFAULT'];

    private string $column;
    private TableBuilder $tableBuilder;
    private ?string $referencesColumn = null;
    private ?string $referencesTable = null;
    private string $onDelete = 'CASCADE';
    private string $onUpdate = 'CASCADE';

    public function __construct(string $column, TableBuilder $tableBuilder)
    {
        $this->column = $column;
        $this->tableBuilder = $tableBuilder;
    }

    public function references(string $column): self
    {
        $this->referencesColumn = Grammar::assertIdentifier($column, 'column name');
        return $this;
    }

    public function on(string $table): self
    {
        $this->referencesTable = Grammar::assertTable($table);
        return $this;
    }

    public function onDelete(string $action): self
    {
        $this->onDelete = self::action($action);
        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->onUpdate = self::action($action);
        return $this;
    }

    public function cascadeOnDelete(): self
    {
        $this->onDelete = 'CASCADE';
        return $this;
    }

    public function nullOnDelete(): self
    {
        $this->onDelete = 'SET NULL';
        return $this;
    }

    public function restrictOnDelete(): self
    {
        $this->onDelete = 'RESTRICT';
        return $this;
    }

    public function getColumn(): string
    {
        return $this->column;
    }

    public function build(string $driver = 'mysql'): string
    {
        if ($this->referencesTable === null || $this->referencesColumn === null) {
            throw new DatabaseException("Foreign key on {$this->column} needs references() and on().");
        }

        $grammar = Grammar::for($driver);
        $name = 'fk_' . str_replace('.', '_', $this->tableBuilder->getTableName()) . '_' . $this->column;
        if (strlen($name) > 60) {
            $name = substr($name, 0, 51) . '_' . substr(md5($name), 0, 8);
        }

        return 'CONSTRAINT ' . $grammar->quote($name) . ' FOREIGN KEY (' . $grammar->quote($this->column) . ') '
            . 'REFERENCES ' . $grammar->wrapTable($this->referencesTable) . ' (' . $grammar->quote($this->referencesColumn) . ') '
            . "ON DELETE {$this->onDelete} ON UPDATE {$this->onUpdate}";
    }

    private static function action(string $action): string
    {
        $action = strtoupper(trim($action));
        if (!in_array($action, self::ACTIONS, true)) {
            throw new DatabaseException("Invalid foreign key action: {$action}");
        }
        return $action;
    }

    // Chainable back to TableBuilder
    public function id(string $name = 'Id'): TableBuilder { return $this->tableBuilder->id($name); }
    public function string(string $name, int $length = 255): ColumnBuilder { return $this->tableBuilder->string($name, $length); }
    public function integer(string $name): ColumnBuilder { return $this->tableBuilder->integer($name); }
    public function foreignId(string $name): ColumnBuilder { return $this->tableBuilder->foreignId($name); }
    public function timestamps(): TableBuilder { return $this->tableBuilder->timestamps(); }
}
