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
 * BelongsTo Relation (Inverse of HasOne/HasMany)
 */
class BelongsTo extends Relation
{
    private bool $constraintsAdded = false;

    /**
     * Get the results of the relationship
     */
    public function getResults(): ?Model
    {
        if (!$this->constraintsAdded) {
            $this->addConstraints();
        }

        return $this->newQuery()->first();
    }

    /**
     * Add constraints to the query
     */
    public function addConstraints(): void
    {
        if ($this->constraintsAdded) {
            return;
        }

        $foreignKey = $this->parent->{$this->foreignKey};
        
        if ($foreignKey !== null) {
            $this->newQuery()->where($this->localKey, '=', $foreignKey);
        }

        $this->constraintsAdded = true;
    }

    /**
     * Add eager loading constraints
     */
    public function addEagerConstraints(array $models): void
    {
        $keys = $this->getKeys($models, $this->foreignKey);
        $this->newQuery()->whereIn($this->localKey, $keys);
    }

    /**
     * Match eagerly loaded results to their parents
     */
    public function match(array $models, array $results, string $relation): array
    {
        $dictionary = $this->buildDictionary($results);

        foreach ($models as $model) {
            $key = $model->{$this->foreignKey};
            
            if (isset($dictionary[$key])) {
                $model->setRelation($relation, $dictionary[$key]);
            } else {
                $model->setRelation($relation, null);
            }
        }

        return $models;
    }

    /**
     * Build dictionary of results keyed by primary key
     */
    protected function buildDictionary(array $results): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            $key = $result->{$this->localKey};
            $dictionary[$key] = $result;
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
     * Associate the model with the parent
     */
    public function associate(Model $model): Model
    {
        $this->parent->{$this->foreignKey} = $model->{$this->localKey};
        $this->parent->setRelation($this->foreignKey, $model);
        
        return $this->parent;
    }

    /**
     * Dissociate the model from the parent
     */
    public function dissociate(): Model
    {
        $this->parent->{$this->foreignKey} = null;
        $this->parent->setRelation($this->foreignKey, null);
        
        return $this->parent;
    }
}
