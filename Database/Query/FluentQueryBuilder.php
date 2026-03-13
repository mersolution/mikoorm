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

/**
 * Modern Fluent Query Builder - replaces SQLView with better API
 * 
 * Features:
 * - Fluent interface
 * - Prepared statements (SQL injection safe)
 * - Subquery support
 * - Having clause
 * - Aggregate functions
 * - Union support
 * - Raw expressions (safe)
 */
class FluentQueryBuilder implements QueryBuilderInterface
{
    protected ConnectionInterface $connection;
    protected string $table = '';
    protected ?string $tableAlias = null;
    protected array $selectColumns = ['*'];
    protected array $joins = [];
    protected array $wheres = [];
    protected array $havings = [];
    protected array $groupBy = [];
    protected array $orderBy = [];
    protected ?int $limit = null;
    protected ?int $offset = null;
    protected array $bindings = [];
    protected int $paramIndex = 1;
    protected bool $distinct = false;
    protected array $unions = [];

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @inheritDoc
     */
    public function select(array|string $columns = ['*']): self
    {
        $this->selectColumns = is_array($columns) ? $columns : [$columns];
        return $this;
    }

    /**
     * Select raw expression
     */
    public function selectRaw(string $expression, array $bindings = []): self
    {
        $this->selectColumns[] = $expression;
        $this->addBindings($bindings);
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
     * @inheritDoc
     */
    public function where(string $column, string $operator, mixed $value): self
    {
        $param = $this->createParam();
        $this->wheres[] = "{$column} {$operator} {$param}";
        $this->bindings[$param] = $value;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function orWhere(string $column, string $operator, mixed $value): self
    {
        $param = $this->createParam();
        $prefix = empty($this->wheres) ? '' : 'OR ';
        $this->wheres[] = "{$prefix}{$column} {$operator} {$param}";
        $this->bindings[$param] = $value;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function whereIn(string $column, array $values): self
    {
        if (empty($values)) {
            $this->wheres[] = '1 = 0'; // Always false
            return $this;
        }

        $params = [];
        foreach ($values as $value) {
            $param = $this->createParam();
            $params[] = $param;
            $this->bindings[$param] = $value;
        }

        $this->wheres[] = "{$column} IN (" . implode(', ', $params) . ")";
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function whereNull(string $column): self
    {
        $this->wheres[] = "{$column} IS NULL";
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function whereNotNull(string $column): self
    {
        $this->wheres[] = "{$column} IS NOT NULL";
        return $this;
    }

    /**
     * Where LIKE
     */
    public function whereLike(string $column, string $value): self
    {
        $param = $this->createParam();
        $this->wheres[] = "{$column} LIKE {$param}";
        $this->bindings[$param] = "%{$value}%";
        return $this;
    }

    /**
     * Where BETWEEN
     */
    public function whereBetween(string $column, mixed $min, mixed $max): self
    {
        $param1 = $this->createParam();
        $param2 = $this->createParam();
        $this->wheres[] = "{$column} BETWEEN {$param1} AND {$param2}";
        $this->bindings[$param1] = $min;
        $this->bindings[$param2] = $max;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = $sql;
        $this->addBindings($bindings);
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
     * Order by raw expression
     */
    public function orderByRaw(string $expression): self
    {
        $this->orderBy[] = $expression;
        return $this;
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
        $this->havings[] = "{$column} {$operator} {$param}";
        $this->bindings[$param] = $value;
        return $this;
    }

    /**
     * Having raw expression
     */
    public function havingRaw(string $expression, array $bindings = []): self
    {
        $this->havings[] = $expression;
        $this->addBindings($bindings);
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
     * Union with another query
     */
    public function union(FluentQueryBuilder $query, bool $all = false): self
    {
        $this->unions[] = [
            'query' => $query,
            'all' => $all,
        ];
        return $this;
    }

    /**
     * Union all
     */
    public function unionAll(FluentQueryBuilder $query): self
    {
        return $this->union($query, true);
    }

    /**
     * @inheritDoc
     */
    public function get(): array
    {
        $sql = $this->toSql();
        $result = $this->connection->execute($sql, array_values($this->bindings));
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
        $originalSelect = $this->selectColumns;
        $this->selectColumns = ['COUNT(*) as count'];
        
        $sql = $this->toSql();
        $result = $this->connection->execute($sql, array_values($this->bindings));
        $data = $result->first();
        
        $this->selectColumns = $originalSelect;
        
        return (int) ($data['count'] ?? 0);
    }

    /**
     * @inheritDoc
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Get sum of column
     */
    public function sum(string $column): int|float
    {
        return $this->aggregate('SUM', $column);
    }

    /**
     * Get average of column
     */
    public function avg(string $column): int|float
    {
        return $this->aggregate('AVG', $column);
    }

    /**
     * Get min value
     */
    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    /**
     * Get max value
     */
    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    /**
     * Aggregate function
     */
    protected function aggregate(string $function, string $column): mixed
    {
        $originalSelect = $this->selectColumns;
        $this->selectColumns = ["{$function}({$column}) as aggregate"];
        
        $sql = $this->toSql();
        $result = $this->connection->execute($sql, array_values($this->bindings));
        $data = $result->first();
        
        $this->selectColumns = $originalSelect;
        
        return $data['aggregate'] ?? 0;
    }

    /**
     * @inheritDoc
     */
    public function toSql(): string
    {
        $sql = $this->buildSelectClause();
        $sql .= $this->buildFromClause();
        $sql .= $this->buildJoinClause();
        $sql .= $this->buildWhereClause();
        $sql .= $this->buildGroupByClause();
        $sql .= $this->buildHavingClause();
        $sql .= $this->buildOrderByClause();
        $sql .= $this->buildLimitClause();
        $sql .= $this->buildUnionClause();

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
     * Build SELECT clause
     */
    protected function buildSelectClause(): string
    {
        $distinct = $this->distinct ? 'DISTINCT ' : '';
        $columns = implode(', ', $this->selectColumns);
        return "SELECT {$distinct}{$columns}";
    }

    /**
     * Build FROM clause
     */
    protected function buildFromClause(): string
    {
        if (empty($this->table)) {
            throw new DatabaseException('No table specified');
        }

        $alias = $this->tableAlias ? " AS {$this->tableAlias}" : '';
        return " FROM {$this->table}{$alias}";
    }

    /**
     * Build JOIN clause
     */
    protected function buildJoinClause(): string
    {
        if (empty($this->joins)) {
            return '';
        }

        $sql = '';
        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} JOIN {$join['table']}";
            if ($join['condition'] !== null) {
                $sql .= " ON {$join['condition']}";
            }
        }

        return $sql;
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
        if (empty($this->havings)) {
            return '';
        }

        return ' HAVING ' . implode(' AND ', $this->havings);
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
        $sql = '';

        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }

        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }

        return $sql;
    }

    /**
     * Build UNION clause
     */
    protected function buildUnionClause(): string
    {
        if (empty($this->unions)) {
            return '';
        }

        $sql = '';
        foreach ($this->unions as $union) {
            $type = $union['all'] ? 'UNION ALL' : 'UNION';
            $sql .= " {$type} (" . $union['query']->toSql() . ")";
            
            // Merge bindings
            $this->bindings = array_merge($this->bindings, $union['query']->getBindings());
        }

        return $sql;
    }

    /**
     * Create parameter placeholder
     */
    protected function createParam(): string
    {
        return ':param_' . $this->paramIndex++;
    }

    /**
     * Add bindings
     */
    protected function addBindings(array $bindings): void
    {
        foreach ($bindings as $value) {
            $param = $this->createParam();
            $this->bindings[$param] = $value;
        }
    }

    /**
     * Clone query builder
     */
    public function clone(): self
    {
        return clone $this;
    }
}
