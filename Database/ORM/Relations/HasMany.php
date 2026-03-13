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
 * HasMany Relation
 */
class HasMany extends Relation
{
    private bool $constraintsAdded = false;

    /**
     * Get the results of the relationship
     */
    public function getResults(): array
    {
        if (!$this->constraintsAdded) {
            $this->addConstraints();
        }

        return $this->newQuery()->get();
    }

    /**
     * Add constraints to the query
     */
    public function addConstraints(): void
    {
        if ($this->constraintsAdded) {
            return;
        }

        $parentKey = $this->parent->{$this->localKey};
        
        if ($parentKey !== null) {
            $this->newQuery()->where($this->foreignKey, '=', $parentKey);
        }

        $this->constraintsAdded = true;
    }

    /**
     * Add eager loading constraints
     */
    public function addEagerConstraints(array $models): void
    {
        $keys = $this->getKeys($models, $this->localKey);
        $this->newQuery()->whereIn($this->foreignKey, $keys);
    }

    /**
     * Match eagerly loaded results to their parents
     */
    public function match(array $models, array $results, string $relation): array
    {
        $dictionary = $this->buildDictionary($results);

        foreach ($models as $model) {
            $key = $model->{$this->localKey};
            
            if (isset($dictionary[$key])) {
                $model->setRelation($relation, $dictionary[$key]);
            } else {
                $model->setRelation($relation, []);
            }
        }

        return $models;
    }

    /**
     * Build dictionary of results keyed by foreign key
     */
    protected function buildDictionary(array $results): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            $key = $result->{$this->foreignKey};
            $dictionary[$key][] = $result;
        }

        return $dictionary;
    }

    /**
     * Get keys from models
     */
    protected function getKeys(array $models, string $key): array
    {
        $keys = [];

        foreach ($models as $model) {
            if (($value = $model->{$key}) !== null) {
                $keys[] = $value;
            }
        }

        return array_unique($keys);
    }

    /**
     * Create a new related model
     */
    public function create(array $attributes): Model
    {
        $attributes[$this->foreignKey] = $this->parent->{$this->localKey};
        return $this->related::create($attributes);
    }

    /**
     * Save a related model
     */
    public function save(Model $model): bool
    {
        $model->{$this->foreignKey} = $this->parent->{$this->localKey};
        return $model->save();
    }
}
