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

/**
 * Repository pattern interface for data access
 */
interface RepositoryInterface
{
    /**
     * Find an entity by its primary key
     *
     * @param mixed $id
     * @return object|null
     */
    public function find(mixed $id): ?object;

    /**
     * Find entities by criteria
     *
     * @param array $criteria Search criteria
     * @param array|null $orderBy Sort order
     * @param int|null $limit Result limit
     * @param int|null $offset Result offset
     * @return array
     */
    public function findBy(
        array $criteria,
        ?array $orderBy = null,
        ?int $limit = null,
        ?int $offset = null
    ): array;

    /**
     * Find a single entity by criteria
     *
     * @param array $criteria
     * @return object|null
     */
    public function findOneBy(array $criteria): ?object;

    /**
     * Find all entities
     *
     * @return array
     */
    public function findAll(): array;

    /**
     * Count entities matching criteria
     *
     * @param array $criteria
     * @return int
     */
    public function count(array $criteria = []): int;

    /**
     * Check if an entity exists
     *
     * @param array $criteria
     * @return bool
     */
    public function exists(array $criteria): bool;

    /**
     * Save an entity
     *
     * @param object $entity
     * @return bool
     */
    public function save(object $entity): bool;

    /**
     * Delete an entity
     *
     * @param object $entity
     * @return bool
     */
    public function delete(object $entity): bool;

    /**
     * Get the entity class name
     *
     * @return string
     */
    public function getEntityClass(): string;
}
