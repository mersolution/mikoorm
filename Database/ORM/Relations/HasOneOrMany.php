<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\ORM\Relations;

use Miko\Database\Exceptions\DatabaseException;
use Miko\Database\ORM\Model;

/**
 * Shared logic of HasOne / HasMany: the foreign key lives on the related table
 */
abstract class HasOneOrMany extends Relation
{
    /** Column on the related table (default: {ParentClass}{PrimaryKey}, e.g. UserId) */
    protected string $foreignKey;

    /** Column on the parent table (default: parent primary key) */
    protected string $localKey;

    public function __construct(Model $parent, string $related, ?string $foreignKey = null, ?string $localKey = null)
    {
        parent::__construct($parent, $related);

        $this->foreignKey = $foreignKey ?? $parent->getForeignKeyName();
        $this->localKey = $localKey ?? $parent->getPrimaryKey();
    }

    protected function addConstraints(): void
    {
        $value = $this->parent->getAttributeValue($this->localKey);

        if ($value === null) {
            $this->query->whereRaw('1 = 0');
            return;
        }

        $this->query->where($this->qualifiedForeignKey(), '=', $value);
    }

    public function addEagerConstraints(array $models): void
    {
        $this->constrained = true;
        $keys = $this->collectKeys($models, $this->localKey);

        if ($keys === []) {
            $this->eagerEmpty = true;
            return;
        }

        $this->query->whereIn($this->qualifiedForeignKey(), $keys);
    }

    /**
     * @return array<string, Model[]>
     */
    protected function buildDictionary(array $results): array
    {
        $dictionary = [];
        $column = self::lastSegment($this->foreignKey);

        foreach ($results as $result) {
            $key = self::keyOf($result->getAttributeValue($column));
            if ($key !== null) {
                $dictionary[$key][] = $result;
            }
        }

        return $dictionary;
    }

    protected function qualifiedForeignKey(): string
    {
        return str_contains($this->foreignKey, '.') ? $this->foreignKey : $this->related::getTable() . '.' . $this->foreignKey;
    }

    protected function parentKeyValue(): mixed
    {
        $value = $this->parent->getAttributeValue($this->localKey);
        if ($value === null) {
            throw new DatabaseException('Save the parent model before creating related models.');
        }
        return $value;
    }

    /**
     * New related model with the foreign key set (not saved)
     */
    public function make(array $attributes = []): Model
    {
        $model = new $this->related($attributes);
        $model->setAttributeValue(self::lastSegment($this->foreignKey), $this->parentKeyValue());
        return $model;
    }

    /**
     * Create and save a related model
     */
    public function create(array $attributes = []): Model
    {
        $model = $this->make($attributes);
        $model->save();
        return $model;
    }

    /**
     * @return Model[]
     */
    public function createMany(array $records): array
    {
        return array_map(fn(array $attributes) => $this->create($attributes), $records);
    }

    /**
     * Attach an existing model to the parent and save it
     */
    public function save(Model $model): bool
    {
        $model->setAttributeValue(self::lastSegment($this->foreignKey), $this->parentKeyValue());
        return $model->save();
    }

    public function getForeignKeyName(): string
    {
        return $this->foreignKey;
    }

    public function getLocalKeyName(): string
    {
        return $this->localKey;
    }
}
