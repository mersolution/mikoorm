<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\ORM;

use Miko\Database\Migration\TableBuilder;
use Miko\Database\ORM\Traits\HasTimestamps;
use Miko\Database\ORM\Traits\SoftDeletes;
use ReflectionClass;
use ReflectionProperty;

/**
 * ModelMetadata - column / relation metadata from PHP attributes on model properties.
 *
 * Only properties declared in your model are columns; the library's own
 * model properties (attributes, original, fillable, ...) are skipped.
 * Declare column properties as protected so magic attribute access keeps working:
 *
 *   #[Column(type: 'VARCHAR', length: 100, nullable: false)]
 *   protected $Name;
 */
class ModelMetadata
{
    private static array $cache = [];
    private static ?array $internal = null;

    public string $tableName;
    public ?string $schema = null;
    public string $primaryKey = 'Id';
    public bool $primaryKeyAutoIncrement = true;
    public ?string $createdAtColumn = null;
    public ?string $updatedAtColumn = null;
    public ?string $softDeleteColumn = null;
    public array $columns = [];
    public array $relationships = [];
    public array $indexes = [];
    public array $foreignKeys = [];

    public static function for(string $modelClass): self
    {
        return self::$cache[$modelClass] ??= new self($modelClass);
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Property names that belong to the library (Model + bundled traits)
     */
    public static function internalProperties(): array
    {
        if (self::$internal === null) {
            $names = [];
            foreach ([Model::class, SoftDeletes::class, HasTimestamps::class] as $class) {
                foreach ((new ReflectionClass($class))->getProperties() as $property) {
                    $names[$property->getName()] = true;
                }
            }
            self::$internal = $names;
        }

        return self::$internal;
    }

    private function __construct(string $modelClass)
    {
        $reflection = new ReflectionClass($modelClass);

        $this->extractTable($reflection, $modelClass);

        if (is_subclass_of($modelClass, Model::class) && !$reflection->isAbstract()) {
            $this->primaryKey = (new $modelClass())->getPrimaryKey();
        }

        $this->extractPropertyAttributes($reflection);
    }

    private function extractTable(ReflectionClass $reflection, string $modelClass): void
    {
        $tableAttributes = $reflection->getAttributes(Table::class);

        if ($tableAttributes !== []) {
            $table = $tableAttributes[0]->newInstance();
            $this->tableName = $table->name;
            $this->schema = $table->schema;
            return;
        }

        $this->tableName = is_subclass_of($modelClass, Model::class)
            ? $modelClass::getTable()
            : 'tbl' . $reflection->getShortName() . 's';
    }

    private function extractPropertyAttributes(ReflectionClass $reflection): void
    {
        $internal = self::internalProperties();

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED) as $property) {
            $propertyName = $property->getName();

            if ($property->isStatic() || isset($internal[$propertyName]) || $property->getAttributes(Ignore::class) !== []) {
                continue;
            }

            $pkAttributes = $property->getAttributes(PrimaryKey::class);
            if ($pkAttributes !== []) {
                $this->primaryKey = $propertyName;
                $this->primaryKeyAutoIncrement = $pkAttributes[0]->newInstance()->autoIncrement;
            }

            $columnAttributes = $property->getAttributes(Column::class);
            if ($columnAttributes !== []) {
                $col = $columnAttributes[0]->newInstance();
                $this->columns[$propertyName] = [
                    'name' => $col->name ?? $propertyName,
                    'type' => $col->type,
                    'length' => $col->length,
                    'precision' => $col->precision,
                    'scale' => $col->scale,
                    'nullable' => $col->nullable,
                    'default' => $col->default,
                    'unique' => $col->unique,
                    'unsigned' => $col->unsigned,
                ];
            } else {
                $this->columns[$propertyName] = [
                    'name' => $propertyName,
                    'type' => null,
                    'length' => null,
                    'precision' => null,
                    'scale' => null,
                    'nullable' => true,
                    'default' => null,
                    'unique' => false,
                    'unsigned' => false,
                ];
            }

            if ($property->getAttributes(CreatedAt::class) !== []) {
                $this->createdAtColumn = $propertyName;
            }

            if ($property->getAttributes(UpdatedAt::class) !== []) {
                $this->updatedAtColumn = $propertyName;
            }

            if ($property->getAttributes(SoftDelete::class) !== []) {
                $this->softDeleteColumn = $propertyName;
            }

            $indexAttributes = $property->getAttributes(Index::class);
            if ($indexAttributes !== []) {
                $idx = $indexAttributes[0]->newInstance();
                $this->indexes[] = [
                    'name' => $idx->name ?? "idx_{$this->tableName}_{$propertyName}",
                    'columns' => [$propertyName],
                ];
            }

            $uniqueAttributes = $property->getAttributes(Unique::class);
            if ($uniqueAttributes !== []) {
                $uniq = $uniqueAttributes[0]->newInstance();
                $this->indexes[] = [
                    'name' => $uniq->name ?? "uq_{$this->tableName}_{$propertyName}",
                    'columns' => [$propertyName],
                    'unique' => true,
                ];
            }

            $fkAttributes = $property->getAttributes(ForeignKey::class);
            if ($fkAttributes !== []) {
                $fk = $fkAttributes[0]->newInstance();
                $this->foreignKeys[] = [
                    'column' => $propertyName,
                    'references_table' => $fk->table,
                    'references_column' => $fk->column,
                    'on_delete' => $fk->onDelete,
                    'on_update' => $fk->onUpdate,
                ];
            }

            $this->extractRelationshipAttributes($property, $propertyName);
        }
    }

    private function extractRelationshipAttributes(ReflectionProperty $property, string $propertyName): void
    {
        $map = [
            HasOne::class => 'hasOne',
            HasMany::class => 'hasMany',
            BelongsTo::class => 'belongsTo',
            BelongsToMany::class => 'belongsToMany',
        ];

        foreach ($map as $attributeClass => $type) {
            $attributes = $property->getAttributes($attributeClass);
            if ($attributes === []) {
                continue;
            }

            $relation = $attributes[0]->newInstance();
            $this->relationships[$propertyName] = ['type' => $type] + get_object_vars($relation);
        }
    }

    /**
     * Build the CREATE TABLE definition from the metadata
     */
    public function applyTo(TableBuilder $table): void
    {
        if (!isset($this->columns[$this->primaryKey])) {
            $this->primaryKeyAutoIncrement ? $table->id($this->primaryKey) : $table->integer($this->primaryKey)->notNull();
        }

        foreach ($this->columns as $propertyName => $column) {
            $name = $column['name'];

            if ($propertyName === $this->primaryKey) {
                if ($this->primaryKeyAutoIncrement) {
                    $table->id($name);
                } else {
                    $type = strtoupper((string) $column['type']);
                    $col = in_array($type, ['VARCHAR', 'STRING', 'UUID', 'CHAR'], true)
                        ? $table->string($name, $column['length'] ?? 36)
                        : $table->integer($name);
                    $col->notNull()->primary();
                }
                continue;
            }

            $col = $this->createColumn($table, $name, $column['type'], $column['length'], $column['precision'], $column['scale']);

            $column['nullable'] ? $col->nullable() : $col->notNull();

            if ($column['unsigned']) {
                $col->unsigned();
            }

            if ($column['default'] !== null) {
                $col->default($column['default']);
            }

            if ($column['unique']) {
                $col->unique();
            }
        }

        foreach ($this->indexes as $index) {
            if (!empty($index['unique'])) {
                $table->unique($index['columns'], $index['name']);
            } else {
                $table->index($index['columns'], $index['name']);
            }
        }

        foreach ($this->foreignKeys as $fk) {
            $table->foreign($fk['column'])
                ->references($fk['references_column'])
                ->on($fk['references_table'])
                ->onDelete($fk['on_delete'])
                ->onUpdate($fk['on_update']);
        }
    }

    private function createColumn(TableBuilder $table, string $name, ?string $type, ?int $length, ?int $precision, ?int $scale): \Miko\Database\Migration\ColumnBuilder
    {
        return match (strtoupper((string) $type)) {
            'INT', 'INTEGER' => $table->integer($name),
            'BIGINT' => $table->bigInteger($name),
            'SMALLINT' => $table->smallInteger($name),
            'TINYINT' => $table->tinyInteger($name),
            'TEXT' => $table->text($name),
            'MEDIUMTEXT' => $table->mediumText($name),
            'LONGTEXT' => $table->longText($name),
            'DECIMAL', 'NUMERIC' => $table->decimal($name, $precision ?? 10, $scale ?? 2),
            'FLOAT' => $table->float($name),
            'DOUBLE' => $table->double($name),
            'BOOLEAN', 'BOOL' => $table->boolean($name),
            'DATE' => $table->date($name),
            'DATETIME' => $table->dateTime($name),
            'TIMESTAMP' => $table->timestamp($name),
            'TIME' => $table->time($name),
            'JSON' => $table->json($name),
            'BLOB', 'BINARY' => $table->binary($name),
            default => $table->string($name, $length ?? 255),
        };
    }

    public function hasSoftDelete(): bool
    {
        return $this->softDeleteColumn !== null;
    }

    public function hasTimestamps(): bool
    {
        return $this->createdAtColumn !== null || $this->updatedAtColumn !== null;
    }

    public function getColumnName(string $propertyName): string
    {
        return $this->columns[$propertyName]['name'] ?? $propertyName;
    }
}
