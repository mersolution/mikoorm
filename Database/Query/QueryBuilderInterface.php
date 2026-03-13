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
 * Query builder interface for constructing SQL queries
 */
interface QueryBuilderInterface
{
    /**
     * Set the columns to select
     *
     * @param array|string $columns
     * @return self
     */
    public function select(array|string $columns = ['*']): self;

    /**
     * Set the table to query from
     *
     * @param string $table
     * @param string|null $alias
     * @return self
     */
    public function from(string $table, ?string $alias = null): self;

    /**
     * Add a join clause
     *
     * @param string $table
     * @param string $condition
     * @param string $type
     * @return self
     */
    public function join(string $table, string $condition, string $type = 'INNER'): self;

    /**
     * Add a left join clause
     *
     * @param string $table
     * @param string $condition
     * @return self
     */
    public function leftJoin(string $table, string $condition): self;

    /**
     * Add a right join clause
     *
     * @param string $table
     * @param string $condition
     * @return self
     */
    public function rightJoin(string $table, string $condition): self;

    /**
     * Add a where clause
     *
     * @param string $column
     * @param string $operator
     * @param mixed $value
     * @return self
     */
    public function where(string $column, string $operator, mixed $value): self;

    /**
     * Add an OR where clause
     *
     * @param string $column
     * @param string $operator
     * @param mixed $value
     * @return self
     */
    public function orWhere(string $column, string $operator, mixed $value): self;

    /**
     * Add a where IN clause
     *
     * @param string $column
     * @param array $values
     * @return self
     */
    public function whereIn(string $column, array $values): self;

    /**
     * Add a where NULL clause
     *
     * @param string $column
     * @return self
     */
    public function whereNull(string $column): self;

    /**
     * Add a where NOT NULL clause
     *
     * @param string $column
     * @return self
     */
    public function whereNotNull(string $column): self;

    /**
     * Add a raw where clause
     *
     * @param string $sql
     * @param array $bindings
     * @return self
     */
    public function whereRaw(string $sql, array $bindings = []): self;

    /**
     * Add an order by clause
     *
     * @param string $column
     * @param string $direction
     * @return self
     */
    public function orderBy(string $column, string $direction = 'ASC'): self;

    /**
     * Add a group by clause
     *
     * @param array|string $columns
     * @return self
     */
    public function groupBy(array|string $columns): self;

    /**
     * Add a having clause
     *
     * @param string $column
     * @param string $operator
     * @param mixed $value
     * @return self
     */
    public function having(string $column, string $operator, mixed $value): self;

    /**
     * Set the limit
     *
     * @param int $limit
     * @return self
     */
    public function limit(int $limit): self;

    /**
     * Set the offset
     *
     * @param int $offset
     * @return self
     */
    public function offset(int $offset): self;

    /**
     * Add DISTINCT clause
     *
     * @return self
     */
    public function distinct(): self;

    /**
     * Execute the query and get all results
     *
     * @return array
     */
    public function get(): array;

    /**
     * Execute the query and get the first result
     *
     * @return array|null
     */
    public function first(): ?array;

    /**
     * Get the count of results
     *
     * @return int
     */
    public function count(): int;

    /**
     * Check if any results exist
     *
     * @return bool
     */
    public function exists(): bool;

    /**
     * Get the SQL query string
     *
     * @return string
     */
    public function toSql(): string;

    /**
     * Get the query bindings
     *
     * @return array
     */
    public function getBindings(): array;
}
