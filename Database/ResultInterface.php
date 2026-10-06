<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database;

/**
 * Query result interface
 */
interface ResultInterface
{
    /**
     * Get all rows from the result
     *
     * @return array
     */
    public function all(): array;

    /**
     * Get the first row from the result
     *
     * @return array|null
     */
    public function first(): ?array;

    /**
     * Get the number of affected rows
     *
     * @return int
     */
    public function count(): int;

    /**
     * Check if the result is empty
     *
     * @return bool
     */
    public function isEmpty(): bool;

    /**
     * Get a specific column from all rows
     *
     * @param string $column
     * @return array
     */
    public function pluck(string $column): array;
}
