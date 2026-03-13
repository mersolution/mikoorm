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

use ReflectionClass;
use ReflectionProperty;

/**
 * ModelMetadata - Extracts and caches model metadata from attributes
 */
class ModelMetadata
{
    private static array $cache = [];

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

    /**
     * Get metadata for a model class (cached)
     */
    public static function for(string $modelClass): self
    {
        if (!isset(self::$cache[$modelClass])) {
            self::$cache[$modelClass] = new self($modelClass);
        }
        return self::$cache[$modelClass];
    }

    /**
     * Clear metadata cache
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    private function __construct(string $modelClass)
    {
        $reflection = new ReflectionClass($modelClass);
        
        $this->extractTableAttribute($reflection, $modelClass);
        $this->extractPropertyAttributes($reflection);
    }

    private function extractTableAttribute(ReflectionClass $reflection, string $modelClass): void
    {
        $tableAttributes = $reflection->getAttributes(Table::class);
        
        if (!empty($tableAttributes)) {
            $table = $tableAttributes[0]->newInstance();
            $this->tableName = $table->name;
            $this->schema = $table->schema;
        } else {
            // Fallback: use static $table property if exists
            if ($reflection->hasProperty('table')) {
                $prop = $reflection->getProperty('table');
                $prop->setAccessible(true);
                $this->tableName = $prop->getValue();
            } else {
                // Generate table name from class name
                $shortName = $reflection->getShortName();
                $this->tableName = 'tbl' . $shortName . 's';
            }
        }
    }

    private function extractPropertyAttributes(ReflectionClass $reflection): void
    {
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED) as $property) {
            $propertyName = $property->getName();
            
            // Skip static properties
            if ($property->isStatic()) {
                continue;
            }

            // Check for Ignore attribute
            if (!empty($property->getAttributes(Ignore::class))) {
                continue;
            }

            // Check for PrimaryKey attribute
            $pkAttributes = $property->getAttributes(PrimaryKey::class);
            if (!empty($pkAttributes)) {
                $pk = $pkAttributes[0]->newInstance();
                $this->primaryKey = $propertyName;
                $this->primaryKeyAutoIncrement = $pk->autoIncrement;
            }

            // Check for Column attribute
            $columnAttributes = $property->getAttributes(Column::class);
            if (!empty($columnAttributes)) {
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
                // Default column mapping
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

            // Check for CreatedAt attribute
            if (!empty($property->getAttributes(CreatedAt::class))) {
                $this->createdAtColumn = $propertyName;
            }

            // Check for UpdatedAt attribute
            if (!empty($property->getAttributes(UpdatedAt::class))) {
                $this->updatedAtColumn = $propertyName;
            }

            // Check for SoftDelete attribute
            if (!empty($property->getAttributes(SoftDelete::class))) {
                $this->softDeleteColumn = $propertyName;
            }

            // Check for Index attribute
            $indexAttributes = $property->getAttributes(Index::class);
            if (!empty($indexAttributes)) {
                $idx = $indexAttributes[0]->newInstance();
                $this->indexes[] = [
                    'name' => $idx->name ?? "idx_{$this->tableName}_{$propertyName}",
                    'columns' => [$propertyName],
                ];
            }

            // Check for Unique attribute
            $uniqueAttributes = $property->getAttributes(Unique::class);
            if (!empty($uniqueAttributes)) {
                $uniq = $uniqueAttributes[0]->newInstance();
                $this->indexes[] = [
                    'name' => $uniq->name ?? "uq_{$this->tableName}_{$propertyName}",
                    'columns' => [$propertyName],
                    'unique' => true,
                ];
            }

            // Check for ForeignKey attribute
            $fkAttributes = $property->getAttributes(ForeignKey::class);
            if (!empty($fkAttributes)) {
                $fk = $fkAttributes[0]->newInstance();
                $this->foreignKeys[] = [
                    'column' => $propertyName,
                    'references_table' => $fk->table,
                    'references_column' => $fk->column,
                    'on_delete' => $fk->onDelete,
                    'on_update' => $fk->onUpdate,
                ];
            }

            // Check for relationship attributes
            $this->extractRelationshipAttributes($property, $propertyName);
        }
    }

    private function extractRelationshipAttributes(ReflectionProperty $property, string $propertyName): void
    {
        // HasOne
        $hasOneAttrs = $property->getAttributes(HasOne::class);
        if (!empty($hasOneAttrs)) {
            $rel = $hasOneAttrs[0]->newInstance();
            $this->relationships[$propertyName] = [
                'type' => 'hasOne',
                'related' => $rel->related,
                'foreignKey' => $rel->foreignKey,
                'localKey' => $rel->localKey,
            ];
        }

        // HasMany
        $hasManyAttrs = $property->getAttributes(HasMany::class);
        if (!empty($hasManyAttrs)) {
            $rel = $hasManyAttrs[0]->newInstance();
            $this->relationships[$propertyName] = [
                'type' => 'hasMany',
                'related' => $rel->related,
                'foreignKey' => $rel->foreignKey,
                'localKey' => $rel->localKey,
            ];
        }

        // BelongsTo
        $belongsToAttrs = $property->getAttributes(BelongsTo::class);
        if (!empty($belongsToAttrs)) {
            $rel = $belongsToAttrs[0]->newInstance();
            $this->relationships[$propertyName] = [
                'type' => 'belongsTo',
                'related' => $rel->related,
                'foreignKey' => $rel->foreignKey,
                'ownerKey' => $rel->ownerKey,
            ];
        }

        // BelongsToMany
        $belongsToManyAttrs = $property->getAttributes(BelongsToMany::class);
        if (!empty($belongsToManyAttrs)) {
            $rel = $belongsToManyAttrs[0]->newInstance();
            $this->relationships[$propertyName] = [
                'type' => 'belongsToMany',
                'related' => $rel->related,
                'pivotTable' => $rel->pivotTable,
                'foreignPivotKey' => $rel->foreignPivotKey,
                'relatedPivotKey' => $rel->relatedPivotKey,
                'parentKey' => $rel->parentKey,
                'relatedKey' => $rel->relatedKey,
            ];
        }
    }

    /**
     * Check if model has soft delete
     */
    public function hasSoftDelete(): bool
    {
        return $this->softDeleteColumn !== null;
    }

    /**
     * Check if model has timestamps
     */
    public function hasTimestamps(): bool
    {
        return $this->createdAtColumn !== null || $this->updatedAtColumn !== null;
    }

    /**
     * Get column name (handles attribute mapping)
     */
    public function getColumnName(string $propertyName): string
    {
        return $this->columns[$propertyName]['name'] ?? $propertyName;
    }
}
