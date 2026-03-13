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

use Miko\Database\Exceptions\DatabaseException;

/**
 * Query builder for ORM models
 */
class QueryBuilder
{
    private Model $model;
    private array $wheres = [];
    private array $bindings = [];
    private array $orderBy = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private array $columns = ['*'];
    private array $eagerLoad = [];
    private array $removedScopes = [];
    private array $groupBy = [];
    private array $joins = [];
    private bool $distinct = false;
    private array $having = [];
    private array $havingBindings = [];
    private bool $withTrashed = false;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * Include soft deleted records in results
     */
    public function withTrashed(): self
    {
        $this->withTrashed = true;
        return $this;
    }

    /**
     * Only get soft deleted records
     */
    public function onlyTrashed(): self
    {
        $this->withTrashed = true;
        // SoftDeletes trait'inde DeletedDate kolonu kullanılıyor
        $this->whereNotNull('DeletedDate');
        return $this;
    }

    /**
     * Add where clause
     */
    public function where(string $column, mixed $operatorOrValue = null, mixed $value = null): self
    {
        // Support where('column', 'value') shortcut (equals)
        if ($value === null && $operatorOrValue !== null) {
            $value = $operatorOrValue;
            $operatorOrValue = '=';
        }
        
        $this->wheres[] = "{$column} {$operatorOrValue} ?";
        $this->bindings[] = $value;
        return $this;
    }

    /**
     * Add where IN clause
     */
    public function whereIn(string $column, array $values): self
    {
        if (empty($values)) {
            // Always false condition
            $this->wheres[] = "1 = 0";
            return $this;
        }

        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->wheres[] = "{$column} IN ({$placeholders})";
        array_push($this->bindings, ...$values);
        
        return $this;
    }

    /**
     * Add where NULL clause
     */
    public function whereNull(string $column): self
    {
        $this->wheres[] = "{$column} IS NULL";
        return $this;
    }

    /**
     * Add where NOT NULL clause
     */
    public function whereNotNull(string $column): self
    {
        $this->wheres[] = "{$column} IS NOT NULL";
        return $this;
    }

    /**
     * Add where LIKE clause
     */
    public function whereLike(string $column, string $value): self
    {
        $this->wheres[] = "{$column} LIKE ?";
        $this->bindings[] = "%{$value}%";
        return $this;
    }

    /**
     * Add OR where clause
     */
    public function orWhere(string $column, string $operator, mixed $value): self
    {
        if (empty($this->wheres)) {
            return $this->where($column, $operator, $value);
        }

        $this->wheres[] = "OR {$column} {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    /**
     * Where NOT IN clause
     */
    public function whereNotIn(string $column, array $values): self
    {
        if (empty($values)) {
            return $this;
        }

        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->wheres[] = "{$column} NOT IN ({$placeholders})";
        array_push($this->bindings, ...$values);
        
        return $this;
    }

    /**
     * Where BETWEEN clause
     */
    public function whereBetween(string $column, array $values): self
    {
        if (count($values) !== 2) {
            throw new DatabaseException('whereBetween requires exactly 2 values');
        }

        $this->wheres[] = "{$column} BETWEEN ? AND ?";
        $this->bindings[] = $values[0];
        $this->bindings[] = $values[1];
        
        return $this;
    }

    /**
     * Where NOT BETWEEN clause
     */
    public function whereNotBetween(string $column, array $values): self
    {
        if (count($values) !== 2) {
            throw new DatabaseException('whereNotBetween requires exactly 2 values');
        }

        $this->wheres[] = "{$column} NOT BETWEEN ? AND ?";
        $this->bindings[] = $values[0];
        $this->bindings[] = $values[1];
        
        return $this;
    }

    /**
     * Where date equals
     */
    public function whereDate(string $column, string $operator, string $value): self
    {
        $this->wheres[] = "DATE({$column}) {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    /**
     * Where month equals
     */
    public function whereMonth(string $column, string $operator, int $value): self
    {
        $this->wheres[] = "MONTH({$column}) {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    /**
     * Where year equals
     */
    public function whereYear(string $column, string $operator, int $value): self
    {
        $this->wheres[] = "YEAR({$column}) {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    /**
     * Where day equals
     */
    public function whereDay(string $column, string $operator, int $value): self
    {
        $this->wheres[] = "DAY({$column}) {$operator} ?";
        $this->bindings[] = $value;
        return $this;
    }

    /**
     * Where comparing two columns
     */
    public function whereColumn(string $first, string $operator, string $second): self
    {
        $this->wheres[] = "{$first} {$operator} {$second}";
        return $this;
    }

    /**
     * Where raw SQL
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = $sql;
        if (!empty($bindings)) {
            array_push($this->bindings, ...$bindings);
        }
        return $this;
    }

    /**
     * OR Where IN clause
     */
    public function orWhereIn(string $column, array $values): self
    {
        if (empty($values)) {
            return $this;
        }

        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $this->wheres[] = "OR {$column} IN ({$placeholders})";
        array_push($this->bindings, ...$values);
        
        return $this;
    }

    /**
     * OR Where NULL clause
     */
    public function orWhereNull(string $column): self
    {
        $this->wheres[] = "OR {$column} IS NULL";
        return $this;
    }

    /**
     * OR Where NOT NULL clause
     */
    public function orWhereNotNull(string $column): self
    {
        $this->wheres[] = "OR {$column} IS NOT NULL";
        return $this;
    }

    /**
     * Conditional query building
     */
    public function when(mixed $condition, callable $callback, ?callable $default = null): self
    {
        if ($condition) {
            $callback($this, $condition);
        } elseif ($default !== null) {
            $default($this, $condition);
        }
        return $this;
    }

    /**
     * Add distinct clause
     */
    public function distinct(): self
    {
        $this->distinct = true;
        return $this;
    }

    /**
     * Add group by clause
     */
    public function groupBy(string|array $columns): self
    {
        $columns = is_array($columns) ? $columns : func_get_args();
        $this->groupBy = array_merge($this->groupBy, $columns);
        return $this;
    }

    /**
     * Add having clause
     */
    public function having(string $column, string $operator, mixed $value): self
    {
        $this->having[] = "{$column} {$operator} ?";
        $this->havingBindings[] = $value;
        return $this;
    }

    /**
     * Add having raw clause
     */
    public function havingRaw(string $sql, array $bindings = []): self
    {
        $this->having[] = $sql;
        foreach ($bindings as $binding) {
            $this->havingBindings[] = $binding;
        }
        return $this;
    }

    /**
     * Add inner join
     */
    public function join(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = "INNER JOIN {$table} ON {$first} {$operator} {$second}";
        return $this;
    }

    /**
     * Add left join
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = "LEFT JOIN {$table} ON {$first} {$operator} {$second}";
        return $this;
    }

    /**
     * Add right join
     */
    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = "RIGHT JOIN {$table} ON {$first} {$operator} {$second}";
        return $this;
    }

    /**
     * Add cross join
     */
    public function crossJoin(string $table): self
    {
        $this->joins[] = "CROSS JOIN {$table}";
        return $this;
    }

    /**
     * Add order by clause
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);
        
        if (!in_array($direction, ['ASC', 'DESC'])) {
            throw new DatabaseException("Invalid order direction: {$direction}");
        }

        $this->orderBy[] = "{$column} {$direction}";
        return $this;
    }

    /**
     * Order by descending (shortcut)
     */
    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * Order by raw SQL
     */
    public function orderByRaw(string $sql): self
    {
        $this->orderBy[] = $sql;
        return $this;
    }

    /**
     * Order by latest (created_at DESC)
     */
    public function latest(string $column = 'CreateDate'): self
    {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * Order by oldest (created_at ASC)
     */
    public function oldest(string $column = 'CreateDate'): self
    {
        return $this->orderBy($column, 'ASC');
    }

    /**
     * Random order (multi-database compatible)
     */
    public function inRandomOrder(): self
    {
        // Get database driver
        $driver = $this->model->getConnection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);
        
        $randomFunction = match($driver) {
            'mysql' => 'RAND()',
            'pgsql' => 'RANDOM()',
            'sqlite' => 'RANDOM()',
            'sqlsrv' => 'NEWID()',
            default => 'RAND()'
        };
        
        $this->orderBy[] = $randomFunction;
        return $this;
    }

    /**
     * Set limit
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Set offset
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Alias for limit()
     */
    public function take(int $value): self
    {
        return $this->limit($value);
    }

    /**
     * Alias for offset()
     */
    public function skip(int $value): self
    {
        return $this->offset($value);
    }

    /**
     * Select specific columns
     */
    public function select(array $columns): self
    {
        $this->columns = $columns;
        return $this;
    }

    /**
     * Set relationships to eager load
     */
    public function with(string|array $relations): self
    {
        $relations = is_array($relations) ? $relations : func_get_args();
        $this->eagerLoad = array_merge($this->eagerLoad, $relations);
        return $this;
    }

    /**
     * Remove a global scope from the query
     */
    public function withoutGlobalScope(string $scope): self
    {
        $this->removedScopes[] = $scope;
        return $this;
    }

    /**
     * Check if a global scope has been removed
     */
    public function hasRemovedScope(string $scope): bool
    {
        return in_array($scope, $this->removedScopes);
    }

    /**
     * Get all results
     */
    public function get(): array
    {
        $sql = $this->buildSelectQuery();
        
        $allBindings = array_merge($this->bindings, $this->havingBindings);
        $result = $this->model->getConnection()->execute($sql, $allBindings);
        
        $models = array_map(
            fn($data) => $this->model::hydrate($data),
            $result->all()
        );

        // Eager load relationships
        if (!empty($this->eagerLoad)) {
            $models = $this->eagerLoadRelations($models);
        }
        
        return $models;
    }

    /**
     * Get first result
     */
    public function first(): ?Model
    {
        $this->limit(1);
        $results = $this->get();
        
        return $results[0] ?? null;
    }

    /**
     * Get count
     */
    public function count(): int
    {
        $originalColumns = $this->columns;
        $this->columns = ['COUNT(*) as count'];
        
        $sql = $this->buildSelectQuery();
        $result = $this->model->getConnection()->execute($sql, $this->bindings);
        
        $this->columns = $originalColumns;
        
        $data = $result->first();
        return (int) ($data['count'] ?? 0);
    }

    /**
     * Check if any results exist
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Check if no results exist
     */
    public function doesntExist(): bool
    {
        return $this->count() === 0;
    }

    /**
     * Paginate results
     */
    public function paginate(int $perPage = 15, int $page = 1): array
    {
        $total = $this->count();
        $offset = ($page - 1) * $perPage;
        
        $this->limit($perPage)->offset($offset);
        $items = $this->get();
        
        return [
            'data' => $items,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int) ceil($total / $perPage),
            'from' => $offset + 1,
            'to' => min($offset + $perPage, $total),
        ];
    }

    /**
     * Update records
     */
    public function update(array $data): int
    {
        if (empty($data)) {
            return 0;
        }

        $sets = array_map(fn($col) => "{$col} = ?", array_keys($data));
        $values = array_merge(array_values($data), $this->bindings);

        $sql = sprintf(
            "UPDATE %s SET %s%s",
            $this->model::getTable(),
            implode(', ', $sets),
            $this->buildWhereClause()
        );

        $result = $this->model->getConnection()->execute($sql, $values);
        return $result->count();
    }

    /**
     * Delete records
     */
    public function delete(): int
    {
        $sql = sprintf(
            "DELETE FROM %s%s",
            $this->model::getTable(),
            $this->buildWhereClause()
        );

        $result = $this->model->getConnection()->execute($sql, $this->bindings);
        return $result->count();
    }

    /**
     * Build SELECT query
     */
    protected function buildSelectQuery(): string
    {
        $columns = implode(', ', $this->columns);
        
        $distinct = $this->distinct ? 'DISTINCT ' : '';
        
        $sql = "SELECT {$distinct}{$columns} FROM " . $this->model::getTable();
        $sql .= $this->buildJoinClause();
        $sql .= $this->buildWhereClause();
        $sql .= $this->buildGroupByClause();
        $sql .= $this->buildHavingClause();
        $sql .= $this->buildOrderByClause();
        $sql .= $this->buildLimitClause();

        return $sql;
    }

    /**
     * Build JOIN clause
     */
    protected function buildJoinClause(): string
    {
        if (empty($this->joins)) {
            return '';
        }

        return ' ' . implode(' ', $this->joins);
    }

    /**
     * Build GROUP BY clause
     */
    protected function buildGroupByClause(): string
    {
        if (empty($this->groupBy)) {
            return '';
        }

        return ' GROUP BY ' . implode(', ', $this->groupBy);
    }

    /**
     * Build HAVING clause
     */
    protected function buildHavingClause(): string
    {
        if (empty($this->having)) {
            return '';
        }

        return ' HAVING ' . implode(' AND ', $this->having);
    }

    /**
     * Build WHERE clause
     */
    protected function buildWhereClause(): string
    {
        if (empty($this->wheres)) {
            return '';
        }

        return ' WHERE ' . implode(' AND ', $this->wheres);
    }

    /**
     * Build ORDER BY clause
     */
    protected function buildOrderByClause(): string
    {
        if (empty($this->orderBy)) {
            return '';
        }

        return ' ORDER BY ' . implode(', ', $this->orderBy);
    }

    /**
     * Build LIMIT clause
     */
    protected function buildLimitClause(): string
    {
        $clause = '';

        if ($this->limit !== null) {
            $clause .= " LIMIT {$this->limit}";
        }

        if ($this->offset !== null) {
            $clause .= " OFFSET {$this->offset}";
        }

        return $clause;
    }

    /**
     * Get SQL query (for debugging)
     */
    public function toSql(): string
    {
        return $this->buildSelectQuery();
    }

    /**
     * Get bindings (for debugging)
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Get first result or throw exception
     */
    public function firstOrFail(): Model
    {
        $result = $this->first();
        
        if ($result === null) {
            throw new DatabaseException("No records found");
        }
        
        return $result;
    }

    /**
     * Get value of a single column from first row
     */
    public function value(string $column): mixed
    {
        $originalColumns = $this->columns;
        $this->columns = [$column];
        
        $sql = $this->buildSelectQuery();
        $result = $this->model->getConnection()->execute($sql, $this->bindings);
        
        $this->columns = $originalColumns;
        
        $data = $result->first();
        return $data[$column] ?? null;
    }

    /**
     * Get array of values from a single column
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $originalColumns = $this->columns;
        $this->columns = $key ? [$key, $column] : [$column];
        
        $sql = $this->buildSelectQuery();
        $result = $this->model->getConnection()->execute($sql, $this->bindings);
        $results = $result->all();
        
        $this->columns = $originalColumns;
        
        if ($key === null) {
            return array_column($results, $column);
        }
        
        $plucked = [];
        foreach ($results as $row) {
            $plucked[$row[$key]] = $row[$column];
        }
        return $plucked;
    }

    /**
     * Chunk results for memory-efficient processing
     */
    public function chunk(int $count, callable $callback): bool
    {
        $page = 1;

        do {
            $results = $this->limit($count)->offset(($page - 1) * $count)->get();
            $countResults = count($results);

            if ($countResults === 0) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            $page++;
        } while ($countResults === $count);

        return true;
    }

    /**
     * Chunk results by ID for memory-efficient processing (better for large datasets)
     */
    public function chunkById(int $count, callable $callback, string $column = 'id'): bool
    {
        $lastId = 0;

        do {
            $clone = clone $this;
            $results = $clone->where($column, '>', $lastId)
                ->orderBy($column)
                ->limit($count)
                ->get();
            
            $countResults = count($results);

            if ($countResults === 0) {
                break;
            }

            if ($callback($results) === false) {
                return false;
            }

            $lastId = $results[count($results) - 1]->getKey();
        } while ($countResults === $count);

        return true;
    }

    /**
     * Increment a column's value
     */
    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        $sql = "UPDATE {$this->model::getTable()} SET {$column} = {$column} + ?";
        $bindings = [$amount];
        
        if (!empty($extra)) {
            $sets = [];
            foreach ($extra as $col => $value) {
                $sets[] = "{$col} = ?";
                $bindings[] = $value;
            }
            $sql .= ', ' . implode(', ', $sets);
        }
        
        $sql .= $this->buildWhereClause();
        $bindings = array_merge($bindings, $this->bindings);

        $result = $this->model->getConnection()->execute($sql, $bindings);
        return $result->count();
    }

    /**
     * Decrement a column's value
     */
    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->increment($column, -$amount, $extra);
    }

    /**
     * Sum aggregate
     */
    public function sum(string $column): int|float
    {
        return $this->aggregate('SUM', $column);
    }

    /**
     * Average aggregate
     */
    public function avg(string $column): int|float
    {
        return $this->aggregate('AVG', $column);
    }

    /**
     * Min aggregate
     */
    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    /**
     * Max aggregate
     */
    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    /**
     * Generic aggregate function
     */
    protected function aggregate(string $function, string $column): mixed
    {
        $originalColumns = $this->columns;
        $this->columns = ["{$function}({$column}) as aggregate"];
        
        $sql = $this->buildSelectQuery();
        $result = $this->model->getConnection()->execute($sql, $this->bindings);
        
        $this->columns = $originalColumns;
        
        $data = $result->first();
        return $data['aggregate'] ?? 0;
    }

    /**
     * Clone query builder
     */
    public function clone(): self
    {
        return clone $this;
    }

    /**
     * Eager load relationships for a collection of models
     */
    protected function eagerLoadRelations(array $models): array
    {
        if (empty($models)) {
            return $models;
        }

        foreach ($this->eagerLoad as $relation) {
            $models = $this->loadRelation($models, $relation);
        }

        return $models;
    }

    /**
     * Load a relationship for a collection of models
     */
    protected function loadRelation(array $models, string $name): array
    {
        // Get the relation from the first model
        $relation = $models[0]->$name();

        if (!($relation instanceof \Miko\Database\ORM\Relations\Relation)) {
            return $models;
        }

        // Add eager constraints
        $relation->addEagerConstraints($models);

        // Get the results
        $results = $relation->getResults();

        // Match the results to their parents
        return $relation->match($models, is_array($results) ? $results : [$results], $name);
    }
}
