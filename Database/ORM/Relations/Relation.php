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

use Miko\Core\Async\Future;
use Miko\Database\Exceptions\DatabaseException;
use Miko\Database\ORM\Model;
use Miko\Database\ORM\QueryBuilder;

/**
 * Base Relation class.
 *
 * Every relation owns ONE query builder. Constraints (parent key filter) are
 * added to that builder once, so $user->posts() only ever returns that
 * user's posts. Builder methods can be chained: $user->posts()->where(...)->get()
 */
abstract class Relation
{
    protected Model $parent;
    protected Model $relatedModel;
    protected string $related;
    protected QueryBuilder $query;
    protected bool $constrained = false;
    protected bool $eagerEmpty = false;

    public function __construct(Model $parent, string $related)
    {
        if (!is_subclass_of($related, Model::class)) {
            throw new DatabaseException("Related class [{$related}] must extend " . Model::class);
        }

        $this->parent = $parent;
        $this->related = $related;
        $this->relatedModel = new $related();
        $this->query = $related::query();
    }

    /**
     * Filter the query by the parent model (lazy loading)
     */
    abstract protected function addConstraints(): void;

    /**
     * Filter the query by a list of parent models (eager loading)
     *
     * @param Model[] $models
     */
    abstract public function addEagerConstraints(array $models): void;

    /**
     * Put eager loaded results on their parents
     *
     * @param Model[] $models
     * @param Model[] $results
     * @return Model[]
     */
    abstract public function match(array $models, array $results, string $relation): array;

    /**
     * Result for the parent model
     */
    abstract public function getResults(): mixed;

    public function getQuery(): QueryBuilder
    {
        return $this->query;
    }

    public function getParent(): Model
    {
        return $this->parent;
    }

    public function getRelated(): Model
    {
        return $this->relatedModel;
    }

    /**
     * Results for eager loading (constraints already added)
     *
     * @return Model[]
     */
    public function getEager(): array
    {
        return $this->eagerEmpty ? [] : $this->query->get();
    }

    /**
     * getEager() as a Future (eager loading of getAsync())
     *
     * @return Future<Model[]>
     */
    public function getEagerAsync(): Future
    {
        return $this->eagerEmpty ? Future::resolved([]) : $this->query->getAsync();
    }

    /**
     * @return Model[]
     */
    public function get(): array
    {
        $this->constrain();
        return $this->query->get();
    }

    public function first(): ?Model
    {
        $this->constrain();
        return $this->query->first();
    }

    public function count(): int
    {
        $this->constrain();
        return $this->query->count();
    }

    public function exists(): bool
    {
        $this->constrain();
        return $this->query->exists();
    }

    /**
     * Forward builder calls; chainable methods keep returning the relation
     */
    public function __call(string $method, array $parameters)
    {
        $this->constrain();
        $result = $this->query->$method(...$parameters);
        return $result === $this->query ? $this : $result;
    }

    public function __clone()
    {
        $this->query = clone $this->query;
    }

    protected function constrain(): void
    {
        if (!$this->constrained) {
            $this->constrained = true;
            $this->addConstraints();
        }
    }

    /**
     * Unique non-null values of $key on the models
     */
    protected function collectKeys(array $models, string $key): array
    {
        $keys = [];
        foreach ($models as $model) {
            $value = $model->getAttributeValue($key);
            if ($value !== null) {
                $keys[self::keyOf($value)] = $value;
            }
        }
        return array_values($keys);
    }

    /**
     * Dictionary key that treats 5 and "5" as the same value
     */
    protected static function keyOf(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return (string) $value;
    }

    protected static function lastSegment(string $column): string
    {
        $parts = explode('.', $column);
        return end($parts);
    }
}
