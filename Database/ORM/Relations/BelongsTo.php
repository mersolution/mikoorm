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

use Miko\Database\ORM\Model;

/**
 * BelongsTo Relation (N:1, foreign key on the parent/child table)
 */
class BelongsTo extends Relation
{
    /** Column on this (child) table, default {RelatedClass}{PrimaryKey}, e.g. UserId */
    protected string $foreignKey;

    /** Column on the related (owner) table, default its primary key */
    protected string $ownerKey;

    protected string $relationName;

    public function __construct(Model $child, string $related, ?string $foreignKey = null, ?string $ownerKey = null, string $relationName = '')
    {
        parent::__construct($child, $related);

        $this->foreignKey = $foreignKey ?? $this->relatedModel->getForeignKeyName();
        $this->ownerKey = $ownerKey ?? $this->relatedModel->getPrimaryKey();
        $this->relationName = $relationName;
    }

    protected function addConstraints(): void
    {
        $value = $this->parent->getAttributeValue($this->foreignKey);

        if ($value === null) {
            $this->query->whereRaw('1 = 0');
            return;
        }

        $this->query->where($this->qualifiedOwnerKey(), '=', $value);
    }

    public function addEagerConstraints(array $models): void
    {
        $this->constrained = true;
        $keys = $this->collectKeys($models, $this->foreignKey);

        if ($keys === []) {
            $this->eagerEmpty = true;
            return;
        }

        $this->query->whereIn($this->qualifiedOwnerKey(), $keys);
    }

    public function getResults(): ?Model
    {
        if ($this->parent->getAttributeValue($this->foreignKey) === null) {
            return null;
        }

        $this->constrain();
        return $this->query->first();
    }

    public function match(array $models, array $results, string $relation): array
    {
        $dictionary = [];
        $ownerColumn = self::lastSegment($this->ownerKey);

        foreach ($results as $result) {
            $key = self::keyOf($result->getAttributeValue($ownerColumn));
            if ($key !== null) {
                $dictionary[$key] = $result;
            }
        }

        foreach ($models as $model) {
            $key = self::keyOf($model->getAttributeValue($this->foreignKey));
            $model->setRelation($relation, $key !== null ? ($dictionary[$key] ?? null) : null);
        }

        return $models;
    }

    /**
     * Point the child at a parent model (or a raw key value); save the child afterwards
     */
    public function associate(Model|int|string $model): Model
    {
        if ($model instanceof Model) {
            $this->parent->setAttributeValue($this->foreignKey, $model->getAttributeValue(self::lastSegment($this->ownerKey)));
            if ($this->relationName !== '') {
                $this->parent->setRelation($this->relationName, $model);
            }
        } else {
            $this->parent->setAttributeValue($this->foreignKey, $model);
            if ($this->relationName !== '') {
                $this->parent->unsetRelation($this->relationName);
            }
        }

        return $this->parent;
    }

    public function dissociate(): Model
    {
        $this->parent->setAttributeValue($this->foreignKey, null);
        if ($this->relationName !== '') {
            $this->parent->setRelation($this->relationName, null);
        }

        return $this->parent;
    }

    protected function qualifiedOwnerKey(): string
    {
        return str_contains($this->ownerKey, '.') ? $this->ownerKey : $this->related::getTable() . '.' . $this->ownerKey;
    }

    public function getForeignKeyName(): string
    {
        return $this->foreignKey;
    }

    public function getOwnerKeyName(): string
    {
        return $this->ownerKey;
    }
}
