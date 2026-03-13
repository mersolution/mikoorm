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

use Miko\Database\ConnectionInterface;
use Miko\Database\Exceptions\DatabaseException;
use Miko\Database\Log\QueryLogger;

/**
 * Modern Query Builder - replaces SQLView and SQLViewBuilder
 * 
 * Features:
 * - Fluent API
 * - Prepared statements (SQL injection safe)
 * - Complex joins
 * - Subqueries
 * - Aggregations
 * - Raw expressions
 */
class QueryBuilder implements QueryBuilderInterface
{
    protected ConnectionInterface $connection;
    protected string $table = '';
    protected ?string $tableAlias = null;
    protected array $columns = ['*'];
    protected array $joins = [];
    protected array $wheres = [];
    protected array $bindings = [];
    protected array $orderBy = [];
    protected array $groupBy = [];
    protected array $having = [];
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected bool $distinct = false;
    protected int $paramCounter = 0;

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @inheritDoc
     */
    public function select(array|string ...$columns): self
    {
        if (empty($columns)) {
            $this->columns = ['*'];
            return $this;
        }
        
        $result = [];
        foreach ($columns as $col) {
            if (is_array($col)) {
                $result = array_merge($result, $col);
            } else {
                $result[] = $col;
            }
        }
        
        $this->columns = $result;
        return $this;
    }

    /**
     * Select raw expression
     */
    public function selectRaw(string $expression, array $bindings = []): self
    {
        $this->columns[] = $expression;
        $this->bindings = array_merge($this->bindings, $bindings);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function from(string $table, ?string $alias = null): self
    {
        $this->table = $table;
        $this->tableAlias = $alias;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function join(string $table, string $condition, string $type = 'INNER'): self
    {
        $this->joins[] = [
            'type' => strtoupper($type),
            'table' => $table,
            'condition' => $condition,
        ];
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function leftJoin(string $table, string $condition): self
    {
        return $this->join($table, $condition, 'LEFT');
    }

    /**
     * @inheritDoc
     */
    public function rightJoin(string $table, string $condition): self
    {
        return $this->join($table, $condition, 'RIGHT');
    }

    /**
     * Cross join
     */
    public function crossJoin(string $table): self
    {
        $this->joins[] = [
            'type' => 'CROSS',
            'table' => $table,
            'condition' => null,
        ];
        return $this;
    }

    /**
     * Simple join using main table key and target table key
     * Example: addJoin('tblcity', 'CityPlateCode', 'PlateCode')
     * Results in: INNER JOIN tblcity ON {mainTable}.CityPlateCode = tblcity.PlateCode
     */
    public function addJoin(string $table, string $mainKey, string $targetKey, string $type = 'INNER'): self
    {
        $mainTable = $this->tableAlias ?? $this->table;
        $condition = "{$mainTable}.{$mainKey} = {$table}.{$targetKey}";
        
        $this->joins[] = [
            'type' => strtoupper($type),
            'table' => $table,
            'condition' => $condition,
        ];
        return $this;
    }

    /**
     * Simple left join using main table key and target table key
     */
    public function addLeftJoin(string $table, string $mainKey, string $targetKey): self
    {
        return $this->addJoin($table, $mainKey, $targetKey, 'LEFT');
    }

    /**
     * Simple right join using main table key and target table key
     */
    public function addRightJoin(string $table, string $mainKey, string $targetKey): self
    {
        return $this->addJoin($table, $mainKey, $targetKey, 'RIGHT');
    }

    /**
     * @inheritDoc
     */
    public function where(string $column, string $operator, mixed $value): self
    {
        $param = $this->createParam();
        $this->wheres[] = ['type' => 'basic', 'column' => $column, 'operator' => $operator, 'param' => $param];
        $this->bindings[$param] = $value;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function orWhere(string $column, string $operator, mixed $value): self
    {
        $param = $this->createParam();
        $this->wheres[] = ['type' => 'or', 'column' => $column, 'operator' => $operator, 'param' => $param];
        $this->bindings[$param] = $value;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function whereIn(string $column, array $values): self
    {
        if (empty($values)) {
            $this->wheres[] = ['type' => 'raw', 'sql' => '1 = 0'];
            return $this;
        }

        $params = [];
        foreach ($values as $value) {
            $param = $this->createParam();
            $params[] = $param;
            $this->bindings[$param] = $value;
        }

        $this->wheres[] = [
            'type' => 'in',
            'column' => $column,
            'params' => $params,
        ];

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function whereNull(string $column): self
    {
        $this->wheres[] = ['type' => 'null', 'column' => $column];
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function whereNotNull(string $column): self
    {
        $this->wheres[] = ['type' => 'not_null', 'column' => $column];
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = ['type' => 'raw', 'sql' => $sql];
        
        foreach ($bindings as $value) {
            $param = $this->createParam();
            $this->bindings[$param] = $value;
        }

        return $this;
    }

    /**
     * Where LIKE - searches for a pattern
     */
    public function whereLike(string $column, string $value, bool $wrapWithPercent = true): self
    {
        $param = $this->createParam();
        $this->wheres[] = ['type' => 'basic', 'column' => $column, 'operator' => 'LIKE', 'param' => $param];
        $this->bindings[$param] = $wrapWithPercent ? "%{$value}%" : $value;
        return $this;
    }

    /**
     * Where NOT LIKE
     */
    public function whereNotLike(string $column, string $value, bool $wrapWithPercent = true): self
    {
        $param = $this->createParam();
        $this->wheres[] = ['type' => 'basic', 'column' => $column, 'operator' => 'NOT LIKE', 'param' => $param];
        $this->bindings[$param] = $wrapWithPercent ? "%{$value}%" : $value;
        return $this;
    }

    /**
     * Where NOT IN
     */
    public function whereNotIn(string $column, array $values): self
    {
        if (empty($values)) {
            return $this;
        }

        $params = [];
        foreach ($values as $value) {
            $param = $this->createParam();
            $params[] = $param;
            $this->bindings[$param] = $value;
        }

        $this->wheres[] = [
            'type' => 'not_in',
            'column' => $column,
            'params' => $params,
        ];

        return $this;
    }

    /**
     * Where between
     */
    public function whereBetween(string $column, array $values): self
    {
        if (count($values) !== 2) {
            throw new DatabaseException('whereBetween requires exactly 2 values');
        }

        $param1 = $this->createParam();
        $param2 = $this->createParam();

        $this->wheres[] = [
            'type' => 'between',
            'column' => $column,
            'param1' => $param1,
            'param2' => $param2,
        ];

        $this->bindings[$param1] = $values[0];
        $this->bindings[$param2] = $values[1];

        return $this;
    }

    /**
     * Where NOT BETWEEN
     */
    public function whereNotBetween(string $column, array $values): self
    {
        if (count($values) !== 2) {
            throw new DatabaseException('whereNotBetween requires exactly 2 values');
        }

        $param1 = $this->createParam();
        $param2 = $this->createParam();

        $this->wheres[] = [
            'type' => 'not_between',
            'column' => $column,
            'param1' => $param1,
            'param2' => $param2,
        ];

        $this->bindings[$param1] = $values[0];
        $this->bindings[$param2] = $values[1];

        return $this;
    }

    /**
     * Where date equals (extracts date part)
     */
    public function whereDate(string $column, string $operator, string $value): self
    {
        $param = $this->createParam();
        $this->wheres[] = ['type' => 'raw', 'sql' => "DATE({$column}) {$operator} {$param}"];
        $this->bindings[$param] = $value;
        return $this;
    }

    /**
     * Where month equals
     */
    public function whereMonth(string $column, string $operator, int $value): self
    {
        $param = $this->createParam();
        $this->wheres[] = ['type' => 'raw', 'sql' => "MONTH({$column}) {$operator} {$param}"];
        $this->bindings[$param] = $value;
        return $this;
    }

    /**
     * Where year equals
     */
    public function whereYear(string $column, string $operator, int $value): self
    {
        $param = $this->createParam();
        $this->wheres[] = ['type' => 'raw', 'sql' => "YEAR({$column}) {$operator} {$param}"];
        $this->bindings[$param] = $value;
        return $this;
    }

    /**
     * Where day equals
     */
    public function whereDay(string $column, string $operator, int $value): self
    {
        $param = $this->createParam();
        $this->wheres[] = ['type' => 'raw', 'sql' => "DAY({$column}) {$operator} {$param}"];
        $this->bindings[$param] = $value;
        return $this;
    }

    /**
     * Where comparing two columns
     */
    public function whereColumn(string $first, string $operator, string $second): self
    {
        $this->wheres[] = ['type' => 'raw', 'sql' => "{$first} {$operator} {$second}"];
        return $this;
    }

    /**
     * OR Where IN
     */
    public function orWhereIn(string $column, array $values): self
    {
        if (empty($values)) {
            return $this;
        }

        $params = [];
        foreach ($values as $value) {
            $param = $this->createParam();
            $params[] = $param;
            $this->bindings[$param] = $value;
        }

        $this->wheres[] = [
            'type' => 'or_in',
            'column' => $column,
            'params' => $params,
        ];

        return $this;
    }

    /**
     * OR Where NULL
     */
    public function orWhereNull(string $column): self
    {
        $this->wheres[] = ['type' => 'or_null', 'column' => $column];
        return $this;
    }

    /**
     * OR Where NOT NULL
     */
    public function orWhereNotNull(string $column): self
    {
        $this->wheres[] = ['type' => 'or_not_null', 'column' => $column];
        return $this;
    }

    /**
     * Conditional query building - only applies callback if condition is true
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
     * @inheritDoc
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
     * Order by raw
     */
    public function orderByRaw(string $sql): self
    {
        $this->orderBy[] = $sql;
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
     * Random order
     */
    public function inRandomOrder(): self
    {
        $this->orderBy[] = 'RAND()';
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
     * @inheritDoc
     */
    public function groupBy(array|string $columns): self
    {
        $this->groupBy = is_array($columns) ? $columns : [$columns];
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function having(string $column, string $operator, mixed $value): self
    {
        $param = $this->createParam();
        $this->having[] = "{$column} {$operator} {$param}";
        $this->bindings[$param] = $value;
        return $this;
    }

    /**
     * Having raw
     */
    public function havingRaw(string $sql, array $bindings = []): self
    {
        $this->having[] = $sql;
        
        foreach ($bindings as $value) {
            $param = $this->createParam();
            $this->bindings[$param] = $value;
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function distinct(): self
    {
        $this->distinct = true;
        return $this;
    }

    /**
     * Execute a raw SQL query and return results
     * 
     * @param string $sql Raw SQL query
     * @param array $bindings Optional parameter bindings
     * @return array Query results
     */
    public function rawQuery(string $sql, array $bindings = []): array
    {
        $result = $this->connection->execute($sql, $bindings);
        return $result->all();
    }

    /**
     * @inheritDoc
     */
    public function get(): array
    {
        $sql = $this->toSql();
        $startTime = microtime(true);
        $result = $this->connection->execute($sql, array_values($this->bindings));
        $timeMs = (microtime(true) - $startTime) * 1000;
        QueryLogger::log($sql, array_values($this->bindings), $timeMs);
        return $result->all();
    }

    /**
     * @inheritDoc
     */
    public function first(): ?array
    {
        $this->limit(1);
        $results = $this->get();
        return $results[0] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function count(): int
    {
        $originalColumns = $this->columns;
        $this->columns = ['COUNT(*) as aggregate'];
        
        $sql = $this->toSql();
        $result = $this->connection->execute($sql, array_values($this->bindings));
        
        $this->columns = $originalColumns;
        
        $row = $result->first();
        return (int) ($row['aggregate'] ?? 0);
    }

    /**
     * @inheritDoc
     */
    public function exists(): bool
    {
        return $this->count() > 0;
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
        
        $sql = $this->toSql();
        $result = $this->connection->execute($sql, array_values($this->bindings));
        
        $this->columns = $originalColumns;
        
        $row = $result->first();
        return $row['aggregate'] ?? 0;
    }

    /**
     * Insert data
     */
    public function insert(array $data): bool
    {
        if (empty($data)) {
            throw new DatabaseException('Insert data cannot be empty');
        }

        $columns = array_keys($data);
        $params = [];
        $bindings = [];

        foreach ($data as $value) {
            $param = $this->createParam();
            $params[] = $param;
            $bindings[$param] = $value;
        }

        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $this->table,
            implode(', ', $columns),
            implode(', ', $params)
        );

        $startTime = microtime(true);
        $this->connection->execute($sql, array_values($bindings));
        $timeMs = (microtime(true) - $startTime) * 1000;
        QueryLogger::log($sql, array_values($bindings), $timeMs);
        return true;
    }

    /**
     * Update data
     */
    public function update(array $data): int
    {
        if (empty($data)) {
            throw new DatabaseException('Update data cannot be empty');
        }

        $sets = [];
        $updateBindings = [];

        foreach ($data as $column => $value) {
            $param = $this->createParam();
            $sets[] = "{$column} = {$param}";
            $updateBindings[$param] = $value;
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets);
        
        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . $this->buildWhereClause();
        }

        $allBindings = array_merge($updateBindings, $this->bindings);
        $startTime = microtime(true);
        $result = $this->connection->execute($sql, array_values($allBindings));
        $timeMs = (microtime(true) - $startTime) * 1000;
        QueryLogger::log($sql, array_values($allBindings), $timeMs);
        
        return $result->count();
    }

    /**
     * Delete data
     */
    public function delete(): int
    {
        $sql = "DELETE FROM {$this->table}";
        
        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . $this->buildWhereClause();
        }

        $startTime = microtime(true);
        $result = $this->connection->execute($sql, array_values($this->bindings));
        $timeMs = (microtime(true) - $startTime) * 1000;
        QueryLogger::log($sql, array_values($this->bindings), $timeMs);
        return $result->count();
    }

    /**
     * @inheritDoc
     */
    public function toSql(): string
    {
        $sql = 'SELECT ';
        
        if ($this->distinct) {
            $sql .= 'DISTINCT ';
        }

        $sql .= implode(', ', $this->columns);
        $sql .= ' FROM ' . $this->table;

        if ($this->tableAlias) {
            $sql .= ' AS ' . $this->tableAlias;
        }

        // Joins
        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} JOIN {$join['table']}";
            if ($join['condition']) {
                $sql .= " ON {$join['condition']}";
            }
        }

        // Where
        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . $this->buildWhereClause();
        }

        // Group by
        if (!empty($this->groupBy)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }

        // Having
        if (!empty($this->having)) {
            $sql .= ' HAVING ' . implode(' AND ', $this->having);
        }

        // Order by
        if (!empty($this->orderBy)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }

        // Limit
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        // Offset
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    /**
     * @inheritDoc
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Build where clause
     */
    protected function buildWhereClause(): string
    {
        $clauses = [];

        foreach ($this->wheres as $i => $where) {
            $isOrType = in_array($where['type'], ['or', 'or_in', 'or_null', 'or_not_null']);
            $prefix = ($i > 0 && $isOrType) ? 'OR ' : ($i > 0 ? 'AND ' : '');

            $clause = match($where['type']) {
                'basic' => "{$where['column']} {$where['operator']} {$where['param']}",
                'or' => "{$where['column']} {$where['operator']} {$where['param']}",
                'in' => "{$where['column']} IN (" . implode(', ', $where['params']) . ")",
                'not_in' => "{$where['column']} NOT IN (" . implode(', ', $where['params']) . ")",
                'or_in' => "{$where['column']} IN (" . implode(', ', $where['params']) . ")",
                'null' => "{$where['column']} IS NULL",
                'not_null' => "{$where['column']} IS NOT NULL",
                'or_null' => "{$where['column']} IS NULL",
                'or_not_null' => "{$where['column']} IS NOT NULL",
                'between' => "{$where['column']} BETWEEN {$where['param1']} AND {$where['param2']}",
                'not_between' => "{$where['column']} NOT BETWEEN {$where['param1']} AND {$where['param2']}",
                'raw' => $where['sql'],
                default => '',
            };

            $clauses[] = $prefix . $clause;
        }

        return implode(' ', $clauses);
    }

    /**
     * Create parameter placeholder
     */
    protected function createParam(): string
    {
        return ':param_' . $this->paramCounter++;
    }

    /**
     * Clone query builder
     */
    public function clone(): self
    {
        return clone $this;
    }

    /**
     * Clear the query builder state for reuse (like Delphi's SQL.Clear)
     */
    public function clear(): self
    {
        $this->table = '';
        $this->tableAlias = null;
        $this->columns = ['*'];
        $this->joins = [];
        $this->wheres = [];
        $this->bindings = [];
        $this->orderBy = [];
        $this->groupBy = [];
        $this->having = [];
        $this->limit = null;
        $this->offset = null;
        $this->distinct = false;
        $this->paramCounter = 0;
        return $this;
    }

    /**
     * Get the last inserted ID
     */
    public function lastInsertId(): int
    {
        return (int) $this->connection->getPdo()->lastInsertId();
    }

    /**
     * Insert and get the last inserted ID
     */
    public function insertGetId(array $data): int
    {
        $this->insert($data);
        return $this->lastInsertId();
    }

    /**
     * Find a record by its primary key
     */
    public function find(int|string $id, string $primaryKey = 'Id'): ?array
    {
        return $this->where($primaryKey, '=', $id)->first();
    }

    /**
     * Find a record by its primary key or throw exception
     */
    public function findOrFail(int|string $id, string $primaryKey = 'Id'): array
    {
        $result = $this->find($id, $primaryKey);
        
        if ($result === null) {
            throw new DatabaseException("Record not found with {$primaryKey} = {$id}");
        }
        
        return $result;
    }

    /**
     * Get first record or throw exception
     */
    public function firstOrFail(): array
    {
        $result = $this->first();
        
        if ($result === null) {
            throw new DatabaseException("No records found");
        }
        
        return $result;
    }

    /**
     * Increment a column's value
     */
    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        $sql = "UPDATE {$this->table} SET {$column} = {$column} + {$amount}";
        
        if (!empty($extra)) {
            $sets = [];
            $extraBindings = [];
            foreach ($extra as $col => $value) {
                $param = $this->createParam();
                $sets[] = "{$col} = {$param}";
                $extraBindings[$param] = $value;
            }
            $sql .= ', ' . implode(', ', $sets);
            $this->bindings = array_merge($this->bindings, $extraBindings);
        }
        
        if (!empty($this->wheres)) {
            $sql .= ' WHERE ' . $this->buildWhereClause();
        }

        $result = $this->connection->execute($sql, array_values($this->bindings));
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
     * Insert or update a record
     */
    public function updateOrInsert(array $attributes, array $values = []): bool
    {
        // Build where conditions from attributes
        foreach ($attributes as $column => $value) {
            $this->where($column, '=', $value);
        }
        
        $exists = $this->exists();
        
        if ($exists) {
            // Reset wheres and rebuild for update
            $this->wheres = [];
            $this->bindings = [];
            
            foreach ($attributes as $column => $value) {
                $this->where($column, '=', $value);
            }
            
            $this->update(array_merge($attributes, $values));
            return true;
        }
        
        // Reset for insert
        $this->wheres = [];
        $this->bindings = [];
        
        $this->insert(array_merge($attributes, $values));
        return true;
    }

    /**
     * Insert multiple records
     */
    public function insertBatch(array $records): bool
    {
        if (empty($records)) {
            return false;
        }

        $columns = array_keys($records[0]);
        $allParams = [];
        $allBindings = [];

        foreach ($records as $record) {
            $rowParams = [];
            foreach ($record as $value) {
                $param = $this->createParam();
                $rowParams[] = $param;
                $allBindings[$param] = $value;
            }
            $allParams[] = '(' . implode(', ', $rowParams) . ')';
        }

        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES %s",
            $this->table,
            implode(', ', $columns),
            implode(', ', $allParams)
        );

        $this->connection->execute($sql, array_values($allBindings));
        return true;
    }

    /**
     * Paginate results
     */
    public function paginate(int $page, int $perPage = 15): array
    {
        $total = $this->count();
        $lastPage = (int) ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;

        $this->limit($perPage)->offset($offset);
        $data = $this->get();

        return [
            'data' => $data,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $lastPage,
            'from' => $offset + 1,
            'to' => min($offset + $perPage, $total),
        ];
    }

    /**
     * Get value of a single column from first row
     */
    public function value(string $column): mixed
    {
        $row = $this->select($column)->first();
        return $row[$column] ?? null;
    }

    /**
     * Get array of values from a single column
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $results = $this->get();
        
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
}
