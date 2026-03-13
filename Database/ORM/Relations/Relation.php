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
use Miko\Database\ORM\QueryBuilder;

/**
 * Base Relation class
 */
abstract class Relation
{
    protected Model $parent;
    protected string $related;
    protected string $foreignKey;
    protected string $localKey;

    public function __construct(Model $parent, string $related, ?string $foreignKey = null, ?string $localKey = null)
    {
        $this->parent = $parent;
        $this->related = $related;
        $this->foreignKey = $foreignKey ?? $this->guessForeignKey();
        $this->localKey = $localKey ?? $this->guessLocalKey();
    }

    /**
     * Get the results of the relationship
     */
    abstract public function getResults(): mixed;

    /**
     * Add constraints to the query
     */
    abstract public function addConstraints(): void;

    /**
     * Add eager loading constraints
     */
    abstract public function addEagerConstraints(array $models): void;

    /**
     * Match eagerly loaded results to their parents
     */
    abstract public function match(array $models, array $results, string $relation): array;

    /**
     * Get a new query builder for the related model
     */
    protected function newQuery(): QueryBuilder
    {
        return $this->related::query();
    }

    /**
     * Get the related model instance
     */
    protected function getRelatedInstance(): Model
    {
        return new $this->related();
    }

    /**
     * Guess the foreign key name
     */
    protected function guessForeignKey(): string
    {
        $parentClass = get_class($this->parent);
        $className = $this->classBasename($parentClass);
        return strtolower($className) . '_id';
    }

    /**
     * Guess the local key name
     */
    protected function guessLocalKey(): string
    {
        return $this->parent->getPrimaryKey();
    }

    /**
     * Get class basename
     */
    protected function classBasename(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts);
    }
}
