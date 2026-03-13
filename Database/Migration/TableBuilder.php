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

/**
 * TableBuilder - Fluent API for building table schemas
 * Similar to Entity Framework / mersolutionCore
 */
class TableBuilder
{
    private string $tableName;
    private string $driver;
    private array $columns = [];
    private array $primaryKeys = [];
    private array $indexes = [];
    private array $foreignKeys = [];
    private array $uniqueConstraints = [];

    public function __construct(string $tableName, string $driver = 'mysql')
    {
        $this->tableName = $tableName;
        $this->driver = $driver;
    }

    /**
     * Auto-incrementing primary key (INT)
     */
    public function id(string $name = 'Id'): self
    {
        $col = new ColumnBuilder($name, 'INT', $this);
        $col->unsigned()->autoIncrement()->notNull();
        $this->columns[] = $col;
        $this->primaryKeys[] = $name;
        return $this;
    }

    /**
     * Auto-incrementing primary key (BIGINT)
     */
    public function bigId(string $name = 'Id'): self
    {
        $col = new ColumnBuilder($name, 'BIGINT', $this);
        $col->unsigned()->autoIncrement()->notNull();
        $this->columns[] = $col;
        $this->primaryKeys[] = $name;
        return $this;
    }

    /**
     * UUID primary key
     */
    public function uuid(string $name = 'Id'): ColumnBuilder
    {
        $col = new ColumnBuilder($name, 'VARCHAR', $this);
        $col->length(36)->notNull();
        $this->columns[] = $col;
        $this->primaryKeys[] = $name;
        return $col;
    }

    /**
     * String column (VARCHAR)
     */
    public function string(string $name, int $length = 255): ColumnBuilder
    {
        $col = new ColumnBuilder($name, 'VARCHAR', $this);
        $col->length($length);
        $this->columns[] = $col;
        return $col;
    }

    /**
     * Text column
     */
    public function text(string $name): ColumnBuilder
    {
        $col = new ColumnBuilder($name, 'TEXT', $this);
        $this->columns[] = $col;
        return $col;
    }

    /**
     * Medium text column
     */
    public function mediumText(string $name): ColumnBuilder
    {
        $col = new ColumnBuilder($name, 'MEDIUMTEXT', $this);
        $this->columns[] = $col;
        return $col;
    }

    /**
     * Long text column
     */
    public function longText(string $name): ColumnBuilder
    {
        $col = new ColumnBuilder($name, 'LONGTEXT', $this);
        $this->columns[] = $col;
        return $col;
    }

    /**
     * Integer column
     */
    public function integer(string $name): ColumnBuilder
    {
        $col = new ColumnBuilder($name, 'INT', $this);
        $this->columns[] = $col;
        return $col;
    }

    /**
     * Big integer column
     */
    public function bigInteger(string $name): ColumnBuilder
    {
        $col = new ColumnBuilder($name, 'BIGINT', $this);
        $this->columns[] = $col;
        return $col;
    }

    /**
     * Small integer column
     */
    public function smallInteger(string $name): ColumnBuilder
    {
        $col = new ColumnBuilder($name, 'SMALLINT', $this);
        $this->columns[] = $col;
        return $col;
    }

    /**
     * Tiny integer column
     */
    public function tinyInteger(string $name): ColumnBuilder
    {
        $col = new ColumnBuilder($name, 'TINYINT', $this);
        $this->columns[] = $col;
        return $col;
    }

    /**
     * Decimal column
     */
    public function decimal(string $name, int $precision = 10, int $scale = 2): ColumnBuilder
    {
        $col = new ColumnBuilder($name, 'DECIMAL', $this);
        $col->precision($precision, $scale);
        $this->columns[] = $col;
        return $col;
    }

    /**
     * Float column
     */
    public function float(string $name): ColumnBuilder
    {
        $col = new ColumnBuilder($name, 'FLOAT', $this);
        $this->columns[] = $col;
        return $col;
    }

    /**
     * Double column
     */
    public function double(string $name): ColumnBuilder
    {
        $col = new ColumnBuilder($name, 'DOUBLE', $this);
        $this->columns[] = $col;
        return $col;
    }

    /**
     * Boolean column
     */
    public function boolean(string $name): ColumnBuilder
    {
        $col = new ColumnBuilder($name, 'TINYINT', $this);
        $col->length(1);
        $this->columns[] = $col;
        return $col;
    }

    /**
     * Date column
     */
    public function date(string $name): ColumnBuilder
    {
        $col = new ColumnBuilder($name, 'DATE', $this);
        $this->columns[] = $col;
        return $col;
    }

    /**
     * DateTime column
     */
    public function dateTime(string $name): ColumnBuilder
    {
        $col = new ColumnBuilder($name, 'DATETIME', $this);
        $this->columns[] = $col;
        return $col;
    }

    /**
     * Timestamp column
     */
    public function timestamp(string $name): ColumnBuilder
    {
        $col = new ColumnBuilder($name, 'TIMESTAMP', $this);
        $this->columns[] = $col;
        return $col;
    }

    /**
     * Time column
     */
    public function time(string $name): ColumnBuilder
    {
        $col = new ColumnBuilder($name, 'TIME', $this);
        $this->columns[] = $col;
        return $col;
    }

    /**
     * JSON column
     */
    public function json(string $name): ColumnBuilder
    {
        $col = new ColumnBuilder($name, 'JSON', $this);
        $this->columns[] = $col;
        return $col;
    }

    /**
     * Binary/Blob column
     */
    public function binary(string $name): ColumnBuilder
    {
        $col = new ColumnBuilder($name, 'BLOB', $this);
        $this->columns[] = $col;
        return $col;
    }

    /**
     * Enum column
     */
    public function enum(string $name, array $values): ColumnBuilder
    {
        $col = new ColumnBuilder($name, 'ENUM', $this);
        $col->allowed($values);
        $this->columns[] = $col;
        return $col;
    }

    /**
     * Add CreatedDate and UpdatedDate columns
     */
    public function timestamps(): self
    {
        $this->dateTime('CreatedDate')->nullable();
        $this->dateTime('UpdatedDate')->nullable();
        return $this;
    }


    /**
     * Foreign key column (integer)
     */
    public function foreignId(string $name): ColumnBuilder
    {
        $col = new ColumnBuilder($name, 'INT', $this);
        $col->unsigned();
        $this->columns[] = $col;
        return $col;
    }

    /**
     * Add foreign key constraint
     */
    public function foreign(string $column): ForeignKeyBuilder
    {
        $fk = new ForeignKeyBuilder($column, $this);
        $this->foreignKeys[] = $fk;
        return $fk;
    }

    /**
     * Add index
     */
    public function index(string|array $columns, ?string $name = null): self
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $name = $name ?? 'idx_' . $this->tableName . '_' . implode('_', $columns);
        $this->indexes[] = [
            'name' => $name,
            'columns' => $columns,
            'unique' => false,
        ];
        return $this;
    }

    /**
     * Add unique constraint
     */
    public function unique(string|array $columns, ?string $name = null): self
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $name = $name ?? 'uq_' . $this->tableName . '_' . implode('_', $columns);
        $this->uniqueConstraints[] = [
            'name' => $name,
            'columns' => $columns,
        ];
        return $this;
    }

    /**
     * Build CREATE TABLE SQL
     */
    public function build(): string
    {
        $columnDefs = [];

        foreach ($this->columns as $col) {
            $columnDefs[] = $col->build();
        }

        // Primary key
        if (!empty($this->primaryKeys)) {
            $columnDefs[] = 'PRIMARY KEY (`' . implode('`, `', $this->primaryKeys) . '`)';
        }

        // Unique constraints
        foreach ($this->uniqueConstraints as $uq) {
            $columnDefs[] = "UNIQUE KEY `{$uq['name']}` (`" . implode('`, `', $uq['columns']) . "`)";
        }

        // Foreign keys
        foreach ($this->foreignKeys as $fk) {
            $columnDefs[] = $fk->build();
        }

        $sql = "CREATE TABLE `{$this->tableName}` (\n    ";
        $sql .= implode(",\n    ", $columnDefs);
        $sql .= "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        return $sql;
    }

    /**
     * Get index creation statements
     */
    public function getIndexStatements(): array
    {
        $statements = [];
        foreach ($this->indexes as $idx) {
            $columns = '`' . implode('`, `', $idx['columns']) . '`';
            $statements[] = "CREATE INDEX `{$idx['name']}` ON `{$this->tableName}` ({$columns})";
        }
        return $statements;
    }

    public function getTableName(): string
    {
        return $this->tableName;
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
    private array $allowed = [];

    public function __construct(string $name, string $type, TableBuilder $tableBuilder)
    {
        $this->name = $name;
        $this->type = $type;
        $this->tableBuilder = $tableBuilder;
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

    public function allowed(array $values): self
    {
        $this->allowed = $values;
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

    /**
     * Add foreign key constraint
     */
    public function references(string $column): ForeignKeyBuilder
    {
        return $this->tableBuilder->foreign($this->name)->references($column);
    }

    /**
     * Build column SQL
     */
    public function build(): string
    {
        $sql = "`{$this->name}` ";

        // Type with length/precision
        $sql .= $this->buildType();

        // Unsigned
        if ($this->unsigned && in_array($this->type, ['INT', 'BIGINT', 'SMALLINT', 'TINYINT', 'DECIMAL', 'FLOAT', 'DOUBLE'])) {
            $sql .= ' UNSIGNED';
        }

        // Nullable
        $sql .= $this->nullable ? ' NULL' : ' NOT NULL';

        // Auto increment
        if ($this->autoIncrement) {
            $sql .= ' AUTO_INCREMENT';
        }

        // Default
        if ($this->hasDefault) {
            if ($this->default === null) {
                $sql .= ' DEFAULT NULL';
            } elseif (is_bool($this->default)) {
                $sql .= ' DEFAULT ' . ($this->default ? '1' : '0');
            } elseif (is_numeric($this->default)) {
                $sql .= ' DEFAULT ' . $this->default;
            } else {
                $sql .= " DEFAULT '" . addslashes($this->default) . "'";
            }
        }

        return $sql;
    }

    private function buildType(): string
    {
        switch ($this->type) {
            case 'VARCHAR':
                return "VARCHAR(" . ($this->length ?? 255) . ")";
            case 'DECIMAL':
                return "DECIMAL(" . ($this->precision ?? 10) . "," . ($this->scale ?? 2) . ")";
            case 'ENUM':
                $values = array_map(fn($v) => "'" . addslashes($v) . "'", $this->allowed);
                return "ENUM(" . implode(', ', $values) . ")";
            case 'TINYINT':
                return $this->length ? "TINYINT({$this->length})" : 'TINYINT';
            default:
                return $this->type;
        }
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
        $this->referencesColumn = $column;
        return $this;
    }

    public function on(string $table): self
    {
        $this->referencesTable = $table;
        return $this;
    }

    public function onDelete(string $action): self
    {
        $this->onDelete = $action;
        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->onUpdate = $action;
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

    public function build(): string
    {
        $name = "fk_{$this->tableBuilder->getTableName()}_{$this->column}";
        return "CONSTRAINT `{$name}` FOREIGN KEY (`{$this->column}`) " .
               "REFERENCES `{$this->referencesTable}` (`{$this->referencesColumn}`) " .
               "ON DELETE {$this->onDelete} ON UPDATE {$this->onUpdate}";
    }

    // Chainable back to TableBuilder
    public function id(string $name = 'Id'): TableBuilder { return $this->tableBuilder->id($name); }
    public function string(string $name, int $length = 255): ColumnBuilder { return $this->tableBuilder->string($name, $length); }
    public function integer(string $name): ColumnBuilder { return $this->tableBuilder->integer($name); }
    public function foreignId(string $name): ColumnBuilder { return $this->tableBuilder->foreignId($name); }
    public function timestamps(): TableBuilder { return $this->tableBuilder->timestamps(); }
}
