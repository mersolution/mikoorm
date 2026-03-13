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
 * BelongsToMany Relation (Many-to-Many with pivot table)
 */
class BelongsToMany extends Relation
{
    protected string $pivotTable;
    protected string $foreignPivotKey;
    protected string $relatedPivotKey;
    private bool $constraintsAdded = false;

    public function __construct(
        Model $parent,
        string $related,
        string $pivotTable,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null
    ) {
        $this->pivotTable = $pivotTable;
        $this->foreignPivotKey = $foreignPivotKey ?? $this->guessForeignPivotKey();
        $this->relatedPivotKey = $relatedPivotKey ?? $this->guessRelatedPivotKey();
        
        parent::__construct($parent, $related, $parentKey, $relatedKey);
    }

    /**
     * Get the results of the relationship
     */
    public function getResults(): array
    {
        if (!$this->constraintsAdded) {
            $this->addConstraints();
        }

        return $this->get();
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
            $this->performJoin();
            $this->newQuery()->where(
                $this->pivotTable . '.' . $this->foreignPivotKey,
                '=',
                $parentKey
            );
        }

        $this->constraintsAdded = true;
    }

    /**
     * Add eager loading constraints
     */
    public function addEagerConstraints(array $models): void
    {
        $keys = $this->getKeys($models, $this->localKey);
        
        $this->performJoin();
        $this->newQuery()->whereIn(
            $this->pivotTable . '.' . $this->foreignPivotKey,
            $keys
        );
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
     * Perform the join for the relationship
     */
    protected function performJoin(): void
    {
        $relatedTable = $this->getRelatedInstance()->getTable();
        $relatedKey = $relatedTable . '.' . $this->foreignKey;
        $pivotKey = $this->pivotTable . '.' . $this->relatedPivotKey;

        $this->newQuery()->join(
            $this->pivotTable,
            $relatedKey . ' = ' . $pivotKey
        );
    }

    /**
     * Get the results with pivot data
     */
    public function get(): array
    {
        $relatedTable = $this->getRelatedInstance()->getTable();
        
        $columns = [
            $relatedTable . '.*',
            $this->pivotTable . '.' . $this->foreignPivotKey . ' as pivot_' . $this->foreignPivotKey,
            $this->pivotTable . '.' . $this->relatedPivotKey . ' as pivot_' . $this->relatedPivotKey,
        ];

        return $this->newQuery()->select(...$columns)->get();
    }

    /**
     * Build dictionary of results
     */
    protected function buildDictionary(array $results): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            $key = $result->{'pivot_' . $this->foreignPivotKey};
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
     * Attach models to the relationship
     */
    public function attach(int|array $ids, array $attributes = []): void
    {
        $ids = is_array($ids) ? $ids : [$ids];
        $parentKey = $this->parent->{$this->localKey};

        foreach ($ids as $id) {
            $pivotData = array_merge([
                $this->foreignPivotKey => $parentKey,
                $this->relatedPivotKey => $id,
            ], $attributes);

            $this->parent->getConnection()->execute(
                "INSERT INTO {$this->pivotTable} (" . implode(', ', array_keys($pivotData)) . ") VALUES (" . 
                implode(', ', array_fill(0, count($pivotData), '?')) . ")",
                array_values($pivotData)
            );
        }
    }

    /**
     * Detach models from the relationship
     */
    public function detach(?array $ids = null): int
    {
        $parentKey = $this->parent->{$this->localKey};

        if ($ids === null) {
            $result = $this->parent->getConnection()->execute(
                "DELETE FROM {$this->pivotTable} WHERE {$this->foreignPivotKey} = ?",
                [$parentKey]
            );
        } else {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $result = $this->parent->getConnection()->execute(
                "DELETE FROM {$this->pivotTable} WHERE {$this->foreignPivotKey} = ? AND {$this->relatedPivotKey} IN ({$placeholders})",
                array_merge([$parentKey], $ids)
            );
        }

        return $result->count();
    }

    /**
     * Sync the relationship
     */
    public function sync(array $ids): void
    {
        $this->detach();
        $this->attach($ids);
    }

    /**
     * Guess foreign pivot key
     */
    protected function guessForeignPivotKey(): string
    {
        return strtolower($this->classBasename(get_class($this->parent))) . '_id';
    }

    /**
     * Guess related pivot key
     */
    protected function guessRelatedPivotKey(): string
    {
        return strtolower($this->classBasename($this->related)) . '_id';
    }
}
