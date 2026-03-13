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

/**
 * MikoSet - Represents a table (Miko entity set)
 * Similar to Entity Framework DbSet / mersolutionCore MerSet
 * 
 * Usage:
 *   public MikoSet $Users;  // Will be auto-initialized with User::class
 */
class MikoSet
{
    private string $modelClass;

    public function __construct(string $modelClass)
    {
        $this->modelClass = $modelClass;
    }

    /**
     * Get all records
     */
    public function all(): array
    {
        return $this->modelClass::all();
    }

    /**
     * Alias for all()
     */
    public function toList(): array
    {
        return $this->all();
    }

    /**
     * Find by primary key
     */
    public function find(mixed $id): ?object
    {
        return $this->modelClass::find($id);
    }

    /**
     * Find or fail
     */
    public function findOrFail(mixed $id): object
    {
        return $this->modelClass::findOrFail($id);
    }

    /**
     * Get first record
     */
    public function first(): ?object
    {
        return $this->modelClass::first();
    }

    /**
     * Where clause
     */
    public function where(string $column, mixed $operatorOrValue, mixed $value = null): QueryBuilder
    {
        if ($value === null) {
            return $this->modelClass::where($column, '=', $operatorOrValue);
        }
        return $this->modelClass::where($column, $operatorOrValue, $value);
    }

    /**
     * Start query builder
     */
    public function query(): QueryBuilder
    {
        return $this->modelClass::query();
    }

    /**
     * Count records
     */
    public function count(): int
    {
        return $this->modelClass::count();
    }

    /**
     * Add (save) entity
     */
    public function add(object $entity): object
    {
        $entity->save();
        return $entity;
    }

    /**
     * Remove (delete) entity
     */
    public function remove(object $entity): void
    {
        $entity->delete();
    }

    /**
     * Create new entity
     */
    public function create(array $data): object
    {
        return $this->modelClass::create($data);
    }

    /**
     * Get model class name
     */
    public function getModelClass(): string
    {
        return $this->modelClass;
    }
}
