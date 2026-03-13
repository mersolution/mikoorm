<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\Query;

/**
 * Query interface for executing queries
 */
interface QueryInterface
{
    /**
     * Set a query parameter
     *
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function setParameter(string $key, mixed $value): self;

    /**
     * Set multiple query parameters
     *
     * @param array $parameters
     * @return self
     */
    public function setParameters(array $parameters): self;

    /**
     * Execute the query and return results
     *
     * @return array
     */
    public function getResult(): array;

    /**
     * Execute the query and return a single result
     *
     * @return mixed
     */
    public function getSingleResult(): mixed;

    /**
     * Execute the query and return a scalar value
     *
     * @return mixed
     */
    public function getSingleScalarResult(): mixed;

    /**
     * Get the SQL query string
     *
     * @return string
     */
    public function getSQL(): string;
}
