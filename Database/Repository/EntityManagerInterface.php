<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\Repository;

use Miko\Database\Query\QueryInterface;

/**
 * Entity manager interface for managing entities
 */
interface EntityManagerInterface
{
    /**
     * Find an entity by class and ID
     *
     * @param string $entityClass
     * @param mixed $id
     * @return object|null
     */
    public function find(string $entityClass, mixed $id): ?object;

    /**
     * Persist an entity (mark for insertion/update)
     *
     * @param object $entity
     * @return void
     */
    public function persist(object $entity): void;

    /**
     * Remove an entity (mark for deletion)
     *
     * @param object $entity
     * @return void
     */
    public function remove(object $entity): void;

    /**
     * Flush all pending changes to the database
     *
     * @return void
     */
    public function flush(): void;

    /**
     * Refresh an entity from the database
     *
     * @param object $entity
     * @return void
     */
    public function refresh(object $entity): void;

    /**
     * Detach an entity from the manager
     *
     * @param object $entity
     * @return void
     */
    public function detach(object $entity): void;

    /**
     * Clear all managed entities
     *
     * @return void
     */
    public function clear(): void;

    /**
     * Create a query
     *
     * @param string $dql Domain query language string
     * @return QueryInterface
     */
    public function createQuery(string $dql): QueryInterface;

    /**
     * Get a repository for an entity class
     *
     * @param string $entityClass
     * @return RepositoryInterface
     */
    public function getRepository(string $entityClass): RepositoryInterface;

    /**
     * Begin a transaction
     *
     * @return void
     */
    public function beginTransaction(): void;

    /**
     * Commit the current transaction
     *
     * @return void
     */
    public function commit(): void;

    /**
     * Rollback the current transaction
     *
     * @return void
     */
    public function rollback(): void;
}
