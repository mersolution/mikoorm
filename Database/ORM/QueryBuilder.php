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

use Closure;
use Miko\Core\Async\Future;
use Miko\Database\Async\AsyncConnection;
use Miko\Database\ConnectionInterface;
use Miko\Database\Exceptions\DatabaseException;
use Miko\Database\ORM\Relations\Relation;
use Miko\Database\Query\Grammar;

/**
 * Query builder for ORM models.
 *
 * - Column names, tables and operators are validated and quoted (Grammar)
 * - Global scopes are applied when the query runs, each in its own
 *   parenthesised group, so orWhere() can never escape a scope
 * - Values are always bound as parameters
 */
class QueryBuilder
{
    private Model $model;
    private array $wheres = [];
    private array $havings = [];
    private array $orders = [];
    private array $groups = [];
    private array $joins = [];
    private array $columns = [];
    private ?int $limit = null;
    private ?int $offset = null;
    private bool $distinct = false;
    /** @var array<string, ?Closure> */
    private array $eagerLoad = [];
    private array $removedScopes = [];
    private bool $allScopesRemoved = false;

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    public function getModel(): Model
    {
        return $this->model;
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->model->getConnection();
    }

    protected function grammar(): Grammar
    {
        return $this->getConnection()->getGrammar();
    }

    /**
     * Prefix a column with the model table: Name -> users.Name
     */
    public function qualifyColumn(string $column): string
    {
        return str_contains($column, '.') ? $column : $this->model::getTable() . '.' . $column;
    }

    // ========================================
    // Scopes
    // ========================================

    public function withoutGlobalScope(string $scope): static
    {
        $this->removedScopes[] = $scope;
        return $this;
    }

    public function withoutGlobalScopes(?array $scopes = null): static
    {
        if ($scopes === null) {
            $this->allScopesRemoved = true;
        } else {
            array_push($this->removedScopes, ...$scopes);
        }
        return $this;
    }

    public function hasRemovedScope(string $scope): bool
    {
        return $this->allScopesRemoved || in_array($scope, $this->removedScopes, true);
    }

    /**
     * Include soft deleted records
     */
    public function withTrashed(): static
    {
        return $this->withoutGlobalScope('soft_deletes');
    }

    /**
     * Only soft deleted records
     */
    public function onlyTrashed(): static
    {
        $this->assertSoftDeletes();
        $this->withoutGlobalScope('soft_deletes');
        return $this->whereNotNull($this->model->getQualifiedDeletedAtColumn());
    }

    /**
     * Exclude soft deleted records (the default)
     */
    public function withoutTrashed(): static
    {
        $this->removedScopes = array_values(array_diff($this->removedScopes, ['soft_deletes']));
        return $this;
    }

    /**
     * Copy of this query with the active global scopes applied as AND groups
     */
    protected function applyScopes(): static
    {
        $scopes = $this->allScopesRemoved ? [] : array_diff_key(
            $this->model::getGlobalScopes(),
            array_flip($this->removedScopes)
        );

        if ($scopes === []) {
            return $this;
        }

        $query = clone $this;
        $query->allScopesRemoved = true;
        $userWheres = $query->wheres;
        $groups = [];

        foreach ($scopes as $scope) {
            $query->wheres = [];
            $scope($query);
            if ($query->wheres !== []) {
                $groups[] = ['type' => 'nested', 'wheres' => $query->wheres, 'boolean' => 'AND'];
            }
        }

        if ($userWheres !== []) {
            $groups[] = ['type' => 'nested', 'wheres' => $userWheres, 'boolean' => 'AND'];
        }

        $query->wheres = $groups;
        return $query;
    }

    /**
     * Call a local scope: User::query()->active() runs User::scopeActive($query)
     */
    public function __call(string $method, array $parameters)
    {
        if ($this->model->hasLocalScope($method)) {
            $this->model->callLocalScope($method, $this, $parameters);
            return $this;
        }

        throw new \BadMethodCallException(sprintf('Call to undefined method %s::%s()', static::class, $method));
    }

    // ========================================
    // Where clauses
    // ========================================

    /**
     * where('Col', 5) / where('Col', '>', 5) / where(['A' => 1, 'B' => 2]) / where(fn($q) => ...)
     */
    public function where(mixed $column, mixed $operator = null, mixed $value = null, string $boolean = 'AND'): static
    {
        $boolean = $this->boolean($boolean);

        if ($column instanceof Closure) {
            return $this->addNestedWhere($column, $boolean);
        }

        if (is_array($column)) {
            return $this->addNestedWhere(function (self $q) use ($column) {
                foreach ($column as $key => $val) {
                    if (is_int($key) && is_array($val)) {
                        $q->where(...array_values($val));
                    } else {
                        $q->where($key, '=', $val);
                    }
                }
            }, $boolean);
        }

        if (!is_string($column)) {
            throw new DatabaseException('where() expects a column name, an array or a Closure.');
        }

        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $operator = Grammar::operator((string) $operator);
        Grammar::assertReference($column);

        if ($value === null && in_array($operator, ['=', '!=', '<>'], true)) {
            return $this->addNull($column, $operator !== '=', $boolean);
        }

        $this->wheres[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => $operator,
            'value' => $this->normalizeValue($value),
            'boolean' => $boolean,
        ];

        return $this;
    }

    public function orWhere(mixed $column, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2 && !($column instanceof Closure) && !is_array($column)) {
            return $this->where($column, '=', $operator, 'OR');
        }

        return $this->where($column, $operator, $value, 'OR');
    }

    public function whereNot(Closure $callback): static
    {
        $nested = new static($this->model);
        $callback($nested);
        if ($nested->wheres !== []) {
            $this->wheres[] = ['type' => 'not', 'wheres' => $nested->wheres, 'boolean' => 'AND'];
        }
        return $this;
    }

    public function whereIn(string $column, array $values, string $boolean = 'AND', bool $not = false): static
    {
        Grammar::assertReference($column);
        $this->wheres[] = [
            'type' => 'in',
            'column' => $column,
            'values' => array_values(array_map(fn($v) => $this->normalizeValue($v), $values)),
            'not' => $not,
            'boolean' => $this->boolean($boolean),
        ];
        return $this;
    }

    public function orWhereIn(string $column, array $values): static
    {
        return $this->whereIn($column, $values, 'OR');
    }

    public function whereNotIn(string $column, array $values): static
    {
        return $this->whereIn($column, $values, 'AND', true);
    }

    public function orWhereNotIn(string $column, array $values): static
    {
        return $this->whereIn($column, $values, 'OR', true);
    }

    public function whereNull(string $column): static
    {
        return $this->addNull($column, false, 'AND');
    }

    public function whereNotNull(string $column): static
    {
        return $this->addNull($column, true, 'AND');
    }

    public function orWhereNull(string $column): static
    {
        return $this->addNull($column, false, 'OR');
    }

    public function orWhereNotNull(string $column): static
    {
        return $this->addNull($column, true, 'OR');
    }

    public function whereBetween(string $column, mixed $valuesOrMin, mixed $max = null, string $boolean = 'AND', bool $not = false): static
    {
        $values = $max !== null ? [$valuesOrMin, $max] : $valuesOrMin;

        if (!is_array($values) || count($values) !== 2) {
            throw new DatabaseException('whereBetween requires exactly 2 values');
        }

        Grammar::assertReference($column);
        $values = array_values($values);
        $this->wheres[] = [
            'type' => 'between',
            'column' => $column,
            'values' => [$this->normalizeValue($values[0]), $this->normalizeValue($values[1])],
            'not' => $not,
            'boolean' => $this->boolean($boolean),
        ];
        return $this;
    }

    public function orWhereBetween(string $column, array $values): static
    {
        return $this->whereBetween($column, $values, null, 'OR');
    }

    public function whereNotBetween(string $column, array $values): static
    {
        return $this->whereBetween($column, $values, null, 'AND', true);
    }

    /**
     * Contains search: whereLike('Name', 'ali') -> Name LIKE '%ali%' (wildcards in the value are literal).
     * Pass $wrapWithPercent = false to use your own LIKE pattern.
     */
    public function whereLike(string $column, string $value, bool $wrapWithPercent = true, string $boolean = 'AND', bool $not = false): static
    {
        Grammar::assertReference($column);
        $this->wheres[] = [
            'type' => 'like',
            'column' => $column,
            'value' => $value,
            'mode' => $wrapWithPercent ? 'contains' : 'raw',
            'not' => $not,
            'boolean' => $this->boolean($boolean),
        ];
        return $this;
    }

    public function orWhereLike(string $column, string $value, bool $wrapWithPercent = true): static
    {
        return $this->whereLike($column, $value, $wrapWithPercent, 'OR');
    }

    public function whereNotLike(string $column, string $value, bool $wrapWithPercent = true): static
    {
        return $this->whereLike($column, $value, $wrapWithPercent, 'AND', true);
    }

    /**
     * Prefix search (can use an index): whereStartsWith('Name', 'Al') -> Name LIKE 'Al%'
     */
    public function whereStartsWith(string $column, string $value, string $boolean = 'AND'): static
    {
        Grammar::assertReference($column);
        $this->wheres[] = [
            'type' => 'like',
            'column' => $column,
            'value' => $value,
            'mode' => 'prefix',
            'not' => false,
            'boolean' => $this->boolean($boolean),
        ];
        return $this;
    }

    /**
     * whereDate('Created', '2026-01-31') / whereDate('Created', '>=', '2026-01-01')
     * Plain dates compile to index friendly ranges where possible.
     */
    public function whereDate(string $column, mixed $operatorOrValue, mixed $value = null, string $boolean = 'AND'): static
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operatorOrValue] : [$operatorOrValue, $value];
        return $this->addDateWhere('date', $column, (string) $operator, $value, $boolean);
    }

    public function orWhereDate(string $column, mixed $operatorOrValue, mixed $value = null): static
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operatorOrValue] : [$operatorOrValue, $value];
        return $this->addDateWhere('date', $column, (string) $operator, $value, 'OR');
    }

    public function whereYear(string $column, mixed $operatorOrValue, mixed $value = null, string $boolean = 'AND'): static
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operatorOrValue] : [$operatorOrValue, $value];
        return $this->addDateWhere('year', $column, (string) $operator, $value, $boolean);
    }

    public function whereMonth(string $column, mixed $operatorOrValue, mixed $value = null, string $boolean = 'AND'): static
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operatorOrValue] : [$operatorOrValue, $value];
        return $this->addDateWhere('month', $column, (string) $operator, $value, $boolean);
    }

    public function whereDay(string $column, mixed $operatorOrValue, mixed $value = null, string $boolean = 'AND'): static
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operatorOrValue] : [$operatorOrValue, $value];
        return $this->addDateWhere('day', $column, (string) $operator, $value, $boolean);
    }

    public function whereTime(string $column, mixed $operatorOrValue, mixed $value = null, string $boolean = 'AND'): static
    {
        [$operator, $value] = func_num_args() === 2 ? ['=', $operatorOrValue] : [$operatorOrValue, $value];
        return $this->addDateWhere('time', $column, (string) $operator, $value, $boolean);
    }

    public function whereColumn(string $first, string $operator, ?string $second = null, string $boolean = 'AND'): static
    {
        if ($second === null) {
            [$operator, $second] = ['=', $operator];
        }

        Grammar::assertReference($first);
        Grammar::assertReference($second);

        $this->wheres[] = [
            'type' => 'column',
            'first' => $first,
            'operator' => Grammar::comparison($operator),
            'second' => $second,
            'boolean' => $this->boolean($boolean),
        ];
        return $this;
    }

    /**
     * Raw SQL condition; always pass values through $bindings
     */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): static
    {
        $this->wheres[] = [
            'type' => 'raw',
            'sql' => $sql,
            'bindings' => array_values($bindings),
            'boolean' => $this->boolean($boolean),
        ];
        return $this;
    }

    public function orWhereRaw(string $sql, array $bindings = []): static
    {
        return $this->whereRaw($sql, $bindings, 'OR');
    }

    /**
     * Where primary key equals / is in
     */
    public function whereKey(mixed $id): static
    {
        $key = $this->qualifyColumn($this->model->getPrimaryKey());
        return is_array($id) ? $this->whereIn($key, $id) : $this->where($key, '=', $id);
    }

    public function when(mixed $condition, callable $callback, ?callable $default = null): static
    {
        if ($condition) {
            $callback($this, $condition);
        } elseif ($default !== null) {
            $default($this, $condition);
        }
        return $this;
    }

    public function unless(mixed $condition, callable $callback, ?callable $default = null): static
    {
        return $this->when(!$condition, $callback, $default);
    }

    // ========================================
    // Select / join / group / order / limit
    // ========================================

    public function distinct(): static
    {
        $this->distinct = true;
        return $this;
    }

    /**
     * select('Id', 'Name') / select(['Id', 'users.Name as UserName', 'COUNT(*) as total'])
     */
    public function select(array|string ...$columns): static
    {
        $list = count($columns) === 1 && is_array($columns[0]) ? $columns[0] : $columns;
        $this->columns = [];
        return $this->addSelect($list);
    }

    public function addSelect(array|string ...$columns): static
    {
        $list = count($columns) === 1 && is_array($columns[0]) ? $columns[0] : $columns;
        foreach ($list as $column) {
            $this->columns[] = Grammar::assertColumn((string) $column);
        }
        return $this;
    }

    public function selectRaw(string $expression, array $bindings = []): static
    {
        $this->columns[] = ['raw' => $expression, 'bindings' => array_values($bindings)];
        return $this;
    }

    public function groupBy(string|array ...$columns): static
    {
        $list = count($columns) === 1 && is_array($columns[0]) ? $columns[0] : $columns;
        foreach ($list as $column) {
            $this->groups[] = Grammar::assertReference((string) $column);
        }
        return $this;
    }

    /**
     * having('Total', '>', 5) / having('COUNT(*)', '>', 1)
     */
    public function having(string $column, string $operator, mixed $value, string $boolean = 'AND'): static
    {
        Grammar::assertColumn($column);
        $this->havings[] = [
            'type' => 'basic',
            'column' => $column,
            'operator' => Grammar::operator($operator),
            'value' => $this->normalizeValue($value),
            'boolean' => $this->boolean($boolean),
        ];
        return $this;
    }

    public function orHaving(string $column, string $operator, mixed $value): static
    {
        return $this->having($column, $operator, $value, 'OR');
    }

    public function havingRaw(string $sql, array $bindings = [], string $boolean = 'AND'): static
    {
        $this->havings[] = ['type' => 'raw', 'sql' => $sql, 'bindings' => array_values($bindings), 'boolean' => $this->boolean($boolean)];
        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): static
    {
        Grammar::assertTable($table);
        Grammar::assertReference($first);
        Grammar::assertReference($second);

        $this->joins[] = [
            'type' => Grammar::joinType($type),
            'table' => $table,
            'first' => $first,
            'operator' => Grammar::comparison($operator),
            'second' => $second,
        ];
        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function rightJoin(string $table, string $first, string $operator, string $second): static
    {
        return $this->join($table, $first, $operator, $second, 'RIGHT');
    }

    public function crossJoin(string $table): static
    {
        $this->joins[] = ['type' => 'CROSS', 'table' => Grammar::assertTable($table)];
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->orders[] = ['column' => Grammar::assertReference($column), 'direction' => Grammar::direction($direction)];
        return $this;
    }

    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'DESC');
    }

    public function orderByRaw(string $sql, array $bindings = []): static
    {
        $this->orders[] = ['raw' => $sql, 'bindings' => array_values($bindings)];
        return $this;
    }

    /**
     * Newest first (model created-at column, default CreatedDate)
     */
    public function latest(?string $column = null): static
    {
        return $this->orderBy($column ?? $this->createdAtColumn(), 'DESC');
    }

    public function oldest(?string $column = null): static
    {
        return $this->orderBy($column ?? $this->createdAtColumn(), 'ASC');
    }

    public function inRandomOrder(): static
    {
        $this->orders[] = ['random' => true];
        return $this;
    }

    /**
     * Remove all ORDER BY clauses
     */
    public function reorder(): static
    {
        $this->orders = [];
        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = max(0, $limit);
        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offset = max(0, $offset);
        return $this;
    }

    public function take(int $value): static
    {
        return $this->limit($value);
    }

    public function skip(int $value): static
    {
        return $this->offset($value);
    }

    public function forPage(int $page, int $perPage = 15): static
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }

    // ========================================
    // Eager loading
    // ========================================

    /**
     * with('posts') / with('posts', 'roles') / with('posts.comments')
     * with(['posts' => fn($q) => $q->where('Published', 1)])
     */
    public function with(string|array $relations, mixed ...$more): static
    {
        if (is_string($relations)) {
            $relations = (count($more) === 1 && $more[0] instanceof Closure)
                ? [$relations => $more[0]]
                : array_merge([$relations], $more);
        }

        foreach ($relations as $name => $constraint) {
            if (is_int($name)) {
                [$name, $constraint] = [$constraint, null];
            }

            if (!is_string($name) || $name === '') {
                throw new DatabaseException('Invalid relation name passed to with().');
            }

            $this->addEagerLoad($name, $constraint instanceof Closure ? $constraint : null);
        }

        return $this;
    }

    public function without(string ...$relations): static
    {
        foreach ($relations as $relation) {
            foreach (array_keys($this->eagerLoad) as $name) {
                if ($name === $relation || str_starts_with($name, $relation . '.')) {
                    unset($this->eagerLoad[$name]);
                }
            }
        }
        return $this;
    }

    public function getEagerLoads(): array
    {
        return $this->eagerLoad;
    }

    private function addEagerLoad(string $name, ?Closure $constraint): void
    {
        $segments = explode('.', $name);
        $path = '';

        foreach ($segments as $i => $segment) {
            $path = $path === '' ? $segment : $path . '.' . $segment;
            if (!array_key_exists($path, $this->eagerLoad)) {
                $this->eagerLoad[$path] = null;
            }
            if ($i === count($segments) - 1 && $constraint !== null) {
                $this->eagerLoad[$path] = $constraint;
            }
        }
    }

    /**
     * Load the configured relations into already fetched models
     *
     * @param Model[] $models
     * @return Model[]
     */
    public function eagerLoadRelations(array $models): array
    {
        if ($models === [] || $this->eagerLoad === []) {
            return $models;
        }

        foreach ($this->topLevelEagerLoads() as $name => [$constraint, $nested]) {
            $models = $this->loadRelation($models, $name, $constraint, $nested);
        }

        return $models;
    }

    /**
     * Async eagerLoadRelations(): the relations of one level load in parallel, deeper levels follow
     *
     * @param Model[] $models
     * @return Future<Model[]>
     */
    public function eagerLoadRelationsAsync(array $models): Future
    {
        if ($models === [] || $this->eagerLoad === []) {
            return Future::resolved($models);
        }

        $loads = [];
        foreach ($this->topLevelEagerLoads() as $name => [$constraint, $nested]) {
            $relation = $this->prepareRelation($models, $name, $constraint, $nested);
            $loads[] = $relation->getEagerAsync()->then(
                static fn(array $results) => $relation->match($models, $results, $name)
            );
        }

        return Future::all($loads)->then(static fn() => $models);
    }

    protected function loadRelation(array $models, string $name, ?Closure $constraint, array $nested): array
    {
        $relation = $this->prepareRelation($models, $name, $constraint, $nested);
        return $relation->match($models, $relation->getEager(), $name);
    }

    private function prepareRelation(array $models, string $name, ?Closure $constraint, array $nested): Relation
    {
        $relation = reset($models)->getRelationInstance($name);
        $relation->addEagerConstraints($models);

        if ($constraint !== null) {
            $constraint($relation->getQuery());
        }

        if ($nested !== []) {
            $relation->getQuery()->with($nested);
        }

        return $relation;
    }

    /**
     * @return array<string, array{0: ?Closure, 1: array<string, ?Closure>}> relation => [constraint, nested relations]
     */
    private function topLevelEagerLoads(): array
    {
        $loads = [];
        foreach ($this->eagerLoad as $name => $constraint) {
            if (str_contains($name, '.')) {
                continue;
            }

            $nested = [];
            foreach ($this->eagerLoad as $childName => $childConstraint) {
                if (str_starts_with($childName, $name . '.')) {
                    $nested[substr($childName, strlen($name) + 1)] = $childConstraint;
                }
            }

            $loads[$name] = [$constraint, $nested];
        }
        return $loads;
    }

    // ========================================
    // Execution
    // ========================================

    /**
     * @return Model[]
     */
    public function get(): array
    {
        [$sql, $bindings] = $this->compileSelect();
        $rows = $this->getConnection()->execute($sql, $bindings)->all();

        $models = $this->model::hydrateMany($rows);

        return $this->eagerLoad !== [] ? $this->eagerLoadRelations($models) : $models;
    }

    /**
     * Lazily iterate models one by one (constant memory)
     */
    public function cursor(): \Generator
    {
        [$sql, $bindings] = $this->compileSelect();

        foreach ($this->getConnection()->cursor($sql, $bindings) as $row) {
            yield $this->model::hydrate($row);
        }
    }

    public function first(): ?Model
    {
        return (clone $this)->limit(1)->get()[0] ?? null;
    }

    public function firstOrFail(): Model
    {
        return $this->first() ?? throw new DatabaseException('No query results for model [' . get_class($this->model) . ']');
    }

    /**
     * Exactly one result (null when none, exception when more than one)
     */
    public function single(): ?Model
    {
        $rows = (clone $this)->limit(2)->get();

        if (count($rows) > 1) {
            throw new DatabaseException('More than one record found for model [' . get_class($this->model) . ']');
        }

        return $rows[0] ?? null;
    }

    public function singleOrFail(): Model
    {
        return $this->single() ?? throw new DatabaseException('No query results for model [' . get_class($this->model) . ']');
    }

    public function find(mixed $id): Model|array|null
    {
        if (is_array($id)) {
            return $this->findMany($id);
        }

        return (clone $this)->whereKey($id)->first();
    }

    public function findOrFail(mixed $id): Model
    {
        return $this->find($id) ?? throw new DatabaseException('No query results for model [' . get_class($this->model) . "] with key {$id}");
    }

    public function findMany(array $ids): array
    {
        return $ids === [] ? [] : (clone $this)->whereKey(array_values($ids))->get();
    }

    public function count(string $column = '*'): int
    {
        [$sql, $bindings] = $this->countStatement($column);
        $row = $this->getConnection()->execute($sql, $bindings)->first();
        return (int) ($row['aggregate'] ?? 0);
    }

    public function exists(): bool
    {
        [$sql, $bindings] = $this->existsStatement();
        return $this->getConnection()->execute($sql, $bindings)->first() !== null;
    }

    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    public function sum(string $column): int|float
    {
        return $this->numeric($this->aggregate('SUM', $column)) ?? 0;
    }

    public function avg(string $column): int|float
    {
        return $this->numeric($this->aggregate('AVG', $column)) ?? 0;
    }

    public function min(string $column): mixed
    {
        $value = $this->aggregate('MIN', $column);
        return $this->numeric($value) ?? $value;
    }

    public function max(string $column): mixed
    {
        $value = $this->aggregate('MAX', $column);
        return $this->numeric($value) ?? $value;
    }

    /**
     * Value of one column from the first row
     */
    public function value(string $column): mixed
    {
        [$sql, $bindings] = $this->valueStatement($column);
        $row = $this->getConnection()->execute($sql, $bindings)->first();

        return $row === null ? null : ($row[$this->resultKey($column)] ?? reset($row));
    }

    /**
     * pluck('Name') / pluck('Name', 'Id')
     */
    public function pluck(string $column, ?string $key = null): array
    {
        [$sql, $bindings] = $this->pluckStatement($column, $key);
        return $this->pluckRows($this->getConnection()->execute($sql, $bindings)->all(), $column, $key);
    }

    /**
     * @return array{data: Model[], total: int, per_page: int, current_page: int, last_page: int, from: int, to: int}
     */
    public function paginate(int $perPage = 15, int $page = 1): array
    {
        $perPage = max(1, $perPage);
        $page = max(1, $page);
        $total = $this->count();
        $items = (clone $this)->forPage($page, $perPage)->get();

        return $this->paginationResult($items, $total, $perPage, $page);
    }

    // ========================================
    // Async execution
    // ========================================
    //
    // Same results as the methods above, as a Future. MySQL / MariaDB, PostgreSQL and
    // SQL Server run them in parallel on extra connections; SQLite and queries inside
    // a transaction run right away on the main connection.

    /**
     * @return Future<Model[]>
     */
    public function getAsync(): Future
    {
        $query = clone $this;
        [$sql, $bindings] = $query->compileSelect();

        return AsyncConnection::select($this->getConnection(), $sql, $bindings)->then(static function (array $rows) use ($query) {
            $models = $query->model::hydrateMany($rows);
            return $query->eagerLoad !== [] ? $query->eagerLoadRelationsAsync($models) : $models;
        });
    }

    /**
     * @return Future<?Model>
     */
    public function firstAsync(): Future
    {
        return (clone $this)->limit(1)->getAsync()->then(static fn(array $models) => $models[0] ?? null);
    }

    /**
     * @return Future<Model|Model[]|null>
     */
    public function findAsync(mixed $id): Future
    {
        if (is_array($id)) {
            return $this->findManyAsync($id);
        }

        return (clone $this)->whereKey($id)->firstAsync();
    }

    /**
     * @return Future<Model[]>
     */
    public function findManyAsync(array $ids): Future
    {
        return $ids === [] ? Future::resolved([]) : (clone $this)->whereKey(array_values($ids))->getAsync();
    }

    /**
     * @return Future<int>
     */
    public function countAsync(string $column = '*'): Future
    {
        return $this->selectAsync($this->countStatement($column))
            ->then(static fn(array $rows) => (int) ($rows[0]['aggregate'] ?? 0));
    }

    /**
     * @return Future<bool>
     */
    public function existsAsync(): Future
    {
        return $this->selectAsync($this->existsStatement())->then(static fn(array $rows) => $rows !== []);
    }

    /**
     * @return Future<int|float>
     */
    public function sumAsync(string $column): Future
    {
        return $this->aggregateAsync('SUM', $column)->then(fn($value) => $this->numeric($value) ?? 0);
    }

    /**
     * @return Future<int|float>
     */
    public function avgAsync(string $column): Future
    {
        return $this->aggregateAsync('AVG', $column)->then(fn($value) => $this->numeric($value) ?? 0);
    }

    public function minAsync(string $column): Future
    {
        return $this->aggregateAsync('MIN', $column)->then(fn($value) => $this->numeric($value) ?? $value);
    }

    public function maxAsync(string $column): Future
    {
        return $this->aggregateAsync('MAX', $column)->then(fn($value) => $this->numeric($value) ?? $value);
    }

    public function valueAsync(string $column): Future
    {
        $key = $this->resultKey($column);
        return $this->selectAsync($this->valueStatement($column))->then(static function (array $rows) use ($key) {
            $row = $rows[0] ?? null;
            return $row === null ? null : ($row[$key] ?? reset($row));
        });
    }

    /**
     * @return Future<array>
     */
    public function pluckAsync(string $column, ?string $key = null): Future
    {
        return $this->selectAsync($this->pluckStatement($column, $key))
            ->then(fn(array $rows) => $this->pluckRows($rows, $column, $key));
    }

    /**
     * Count and page run in parallel
     *
     * @return Future<array{data: Model[], total: int, per_page: int, current_page: int, last_page: int, from: int, to: int}>
     */
    public function paginateAsync(int $perPage = 15, int $page = 1): Future
    {
        $perPage = max(1, $perPage);
        $page = max(1, $page);

        return Future::all([
            'total' => $this->countAsync(),
            'items' => (clone $this)->forPage($page, $perPage)->getAsync(),
        ])->then(fn(array $r) => $this->paginationResult($r['items'], $r['total'], $perPage, $page));
    }

    /**
     * Process results in pages (ordered by primary key when no order is given)
     */
    public function chunk(int $count, callable $callback): bool
    {
        $count = max(1, $count);
        $base = clone $this;
        if ($base->orders === []) {
            $base->orderBy($base->qualifyColumn($this->model->getPrimaryKey()));
        }

        $page = 1;
        do {
            $results = (clone $base)->forPage($page, $count)->get();
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
     * Keyset pagination - stays fast on large tables
     */
    public function chunkById(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool
    {
        $count = max(1, $count);
        $column ??= $this->qualifyColumn($this->model->getPrimaryKey());
        $alias ??= $this->resultKey($column);
        $lastId = null;

        do {
            $query = (clone $this)->reorder();
            if ($lastId !== null) {
                $query->where($column, '>', $lastId);
            }
            $results = $query->orderBy($column)->limit($count)->get();
            $countResults = count($results);

            if ($countResults === 0) {
                break;
            }

            if ($callback($results) === false) {
                return false;
            }

            $lastId = end($results)->getAttributeValue($alias);
            if ($lastId === null) {
                throw new DatabaseException("chunkById: column [{$alias}] is missing in the results.");
            }
        } while ($countResults === $count);

        return true;
    }

    /**
     * Run a callback for every model, loading $count at a time
     */
    public function each(callable $callback, int $count = 1000): bool
    {
        return $this->chunk($count, function (array $models) use ($callback) {
            foreach ($models as $model) {
                if ($callback($model) === false) {
                    return false;
                }
            }
            return true;
        });
    }

    // ========================================
    // Writes
    // ========================================

    /**
     * Mass update matching rows. Soft-delete and other global scopes still apply.
     */
    public function update(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        $this->assertNoJoins('update');

        if ($this->model->usesTimestamps()) {
            $updatedAt = $this->model->getUpdatedAtColumn();
            if (!array_key_exists($updatedAt, $values)) {
                $values[$updatedAt] = $this->model->freshTimestampString();
            }
        }

        $query = $this->applyScopes();
        $grammar = $this->grammar();
        $sets = [];
        $bindings = [];

        foreach ($values as $column => $value) {
            $sets[] = $grammar->wrap(Grammar::assertReference((string) $column)) . ' = ?';
            $bindings[] = $this->model->toDatabaseValue((string) $column, $value);
        }

        $where = $query->compileWheres($query->wheres, $bindings);
        $sql = 'UPDATE ' . $grammar->wrapTable($this->model::getTable()) . ' SET ' . implode(', ', $sets)
            . ($where !== '' ? ' WHERE ' . $where : '');

        return $this->getConnection()->execute($sql, $bindings)->count();
    }

    /**
     * Delete matching rows. Soft-delete models get DeletedAt set instead.
     */
    public function delete(): int
    {
        if ($this->model->usesSoftDeletes()) {
            return $this->update([$this->model::getDeletedAtColumn() => $this->model->freshTimestampString()]);
        }

        return $this->forceDelete();
    }

    /**
     * Physically delete matching rows
     */
    public function forceDelete(): int
    {
        $this->assertNoJoins('delete');

        $query = $this->applyScopes();
        $bindings = [];
        $where = $query->compileWheres($query->wheres, $bindings);
        $sql = 'DELETE FROM ' . $this->grammar()->wrapTable($this->model::getTable()) . ($where !== '' ? ' WHERE ' . $where : '');

        return $this->getConnection()->execute($sql, $bindings)->count();
    }

    /**
     * Restore soft deleted rows
     */
    public function restore(): int
    {
        $this->assertSoftDeletes();
        return (clone $this)->withTrashed()->update([$this->model::getDeletedAtColumn() => null]);
    }

    /**
     * Atomic increment: SET col = col + ?
     */
    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        $this->assertNoJoins('increment');

        $grammar = $this->grammar();
        $wrapped = $grammar->wrap(Grammar::assertReference($column));
        $sets = ["{$wrapped} = {$wrapped} + ?"];
        $bindings = [$amount];

        if ($this->model->usesTimestamps() && !array_key_exists($this->model->getUpdatedAtColumn(), $extra)) {
            $extra[$this->model->getUpdatedAtColumn()] = $this->model->freshTimestampString();
        }

        foreach ($extra as $col => $value) {
            $sets[] = $grammar->wrap(Grammar::assertReference((string) $col)) . ' = ?';
            $bindings[] = $this->model->toDatabaseValue((string) $col, $value);
        }

        $query = $this->applyScopes();
        $where = $query->compileWheres($query->wheres, $bindings);
        $sql = 'UPDATE ' . $grammar->wrapTable($this->model::getTable()) . ' SET ' . implode(', ', $sets)
            . ($where !== '' ? ' WHERE ' . $where : '');

        return $this->getConnection()->execute($sql, $bindings)->count();
    }

    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->increment($column, -$amount, $extra);
    }

    // ========================================
    // SQL
    // ========================================

    public function toSql(): string
    {
        return $this->compileSelect()[0];
    }

    public function getBindings(): array
    {
        return $this->compileSelect()[1];
    }

    public function clone(): static
    {
        return clone $this;
    }

    /**
     * @return array{0: string, 1: array}
     */
    protected function compileSelect(): array
    {
        $query = $this->applyScopes();
        $grammar = $this->grammar();
        $bindings = [];

        $columns = [];
        foreach ($query->columns === [] ? ['*'] : $query->columns as $column) {
            if (is_array($column)) {
                $columns[] = $column['raw'];
                array_push($bindings, ...$column['bindings']);
            } else {
                $columns[] = $column === '*' && $query->joins !== []
                    ? $grammar->wrap($this->model::getTable() . '.*')
                    : $grammar->wrap($column);
            }
        }

        $sql = 'SELECT ' . ($query->distinct ? 'DISTINCT ' : '') . implode(', ', $columns)
            . ' FROM ' . $grammar->wrapTable($this->model::getTable());

        foreach ($query->joins as $join) {
            $sql .= ' ' . $join['type'] . ' JOIN ' . $grammar->wrapTable($join['table']);
            if ($join['type'] !== 'CROSS') {
                $sql .= ' ON ' . $grammar->wrap($join['first']) . ' ' . $join['operator'] . ' ' . $grammar->wrap($join['second']);
            }
        }

        $where = $query->compileWheres($query->wheres, $bindings);
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        if ($query->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', array_map(fn($g) => $grammar->wrap($g), $query->groups));
        }

        $having = $query->compileWheres($query->havings, $bindings);
        if ($having !== '') {
            $sql .= ' HAVING ' . $having;
        }

        if ($query->orders !== []) {
            $orders = [];
            foreach ($query->orders as $order) {
                if (isset($order['random'])) {
                    $orders[] = $grammar->random();
                } elseif (isset($order['raw'])) {
                    $orders[] = $order['raw'];
                    array_push($bindings, ...$order['bindings']);
                } else {
                    $orders[] = $grammar->wrap($order['column']) . ' ' . $order['direction'];
                }
            }
            $sql .= ' ORDER BY ' . implode(', ', $orders);
        }

        $sql .= $grammar->compileLimit($query->limit, $query->offset, $query->orders !== []);

        return [$sql, $bindings];
    }

    protected function compileWheres(array $wheres, array &$bindings): string
    {
        $sql = '';

        foreach ($wheres as $where) {
            $fragment = $this->compileWhere($where, $bindings);
            if ($fragment === '') {
                continue;
            }
            $sql .= ($sql === '' ? '' : ' ' . $where['boolean'] . ' ') . $fragment;
        }

        return $sql;
    }

    private function compileWhere(array $where, array &$bindings): string
    {
        $grammar = $this->grammar();

        switch ($where['type']) {
            case 'basic':
                $bindings[] = $where['value'];
                return $grammar->wrap($where['column']) . ' ' . $where['operator'] . ' ?';

            case 'null':
                return $grammar->wrap($where['column']) . ($where['not'] ? ' IS NOT NULL' : ' IS NULL');

            case 'in':
                if ($where['values'] === []) {
                    return $where['not'] ? '1 = 1' : '1 = 0';
                }
                array_push($bindings, ...$where['values']);
                return $grammar->wrap($where['column']) . ($where['not'] ? ' NOT IN (' : ' IN (')
                    . implode(', ', array_fill(0, count($where['values']), '?')) . ')';

            case 'between':
                array_push($bindings, ...$where['values']);
                return $grammar->wrap($where['column']) . ($where['not'] ? ' NOT BETWEEN ? AND ?' : ' BETWEEN ? AND ?');

            case 'like':
                $value = match ($where['mode']) {
                    'contains' => '%' . $grammar->likeEscape($where['value']) . '%',
                    'prefix' => $grammar->likeEscape($where['value']) . '%',
                    default => $where['value'],
                };
                $bindings[] = $value;
                return $grammar->wrap($where['column']) . ($where['not'] ? ' NOT LIKE ?' : ' LIKE ?')
                    . ($where['mode'] === 'raw' ? '' : $grammar->likeEscapeClause());

            case 'column':
                return $grammar->wrap($where['first']) . ' ' . $where['operator'] . ' ' . $grammar->wrap($where['second']);

            case 'raw':
                array_push($bindings, ...$where['bindings']);
                return $where['sql'];

            case 'nested':
                $inner = $this->compileWheres($where['wheres'], $bindings);
                return $inner === '' ? '' : '(' . $inner . ')';

            case 'not':
                $inner = $this->compileWheres($where['wheres'], $bindings);
                return $inner === '' ? '' : 'NOT (' . $inner . ')';

            case 'date':
                $bindings[] = $where['value'];
                return $grammar->datePart($where['part'], $grammar->wrap($where['column'])) . ' ' . $where['operator'] . ' ?';

            case 'range':
                $wrapped = $grammar->wrap($where['column']);
                if ($where['from'] !== null && $where['to'] !== null) {
                    array_push($bindings, $where['from'], $where['to']);
                    return "({$wrapped} >= ? AND {$wrapped} < ?)";
                }
                if ($where['from'] !== null) {
                    $bindings[] = $where['from'];
                    return "{$wrapped} >= ?";
                }
                $bindings[] = $where['to'];
                return "{$wrapped} < ?";
        }

        throw new DatabaseException("Unknown where type: {$where['type']}");
    }

    // ========================================
    // Helpers
    // ========================================

    private function addNestedWhere(Closure $callback, string $boolean): static
    {
        $nested = new static($this->model);
        $nested->allScopesRemoved = true;
        $callback($nested);

        if ($nested->wheres !== []) {
            $this->wheres[] = ['type' => 'nested', 'wheres' => $nested->wheres, 'boolean' => $boolean];
        }

        return $this;
    }

    private function addNull(string $column, bool $not, string $boolean): static
    {
        Grammar::assertReference($column);
        $this->wheres[] = ['type' => 'null', 'column' => $column, 'not' => $not, 'boolean' => $this->boolean($boolean)];
        return $this;
    }

    private function addDateWhere(string $part, string $column, string $operator, mixed $value, string $boolean): static
    {
        Grammar::assertReference($column);
        $operator = Grammar::comparison($operator);
        $boolean = $this->boolean($boolean);

        if ($value instanceof \DateTimeInterface) {
            $value = $part === 'year' ? (int) $value->format('Y') : $value->format('Y-m-d');
        }

        // Sargable ranges: DATE(col) = d  ->  col >= d AND col < d+1
        $range = null;
        if ($part === 'date' && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $next = (new \DateTimeImmutable($value))->modify('+1 day')->format('Y-m-d');
            $range = [$value, $next];
        } elseif ($part === 'year' && is_numeric($value) && (int) $value > 0) {
            $range = [sprintf('%04d-01-01', (int) $value), sprintf('%04d-01-01', (int) $value + 1)];
        }

        if ($range !== null && $operator !== '!=' && $operator !== '<>') {
            [$start, $end] = $range;
            [$from, $to] = match ($operator) {
                '=' => [$start, $end],
                '<' => [null, $start],
                '<=' => [null, $end],
                '>' => [$end, null],
                '>=' => [$start, null],
            };
            $this->wheres[] = ['type' => 'range', 'column' => $column, 'from' => $from, 'to' => $to, 'boolean' => $boolean];
            return $this;
        }

        $this->wheres[] = [
            'type' => 'date',
            'part' => $part,
            'column' => $column,
            'operator' => $operator,
            'value' => is_numeric($value) && $part !== 'date' && $part !== 'time' ? (int) $value : $value,
            'boolean' => $boolean,
        ];
        return $this;
    }

    private function forAggregate(): static
    {
        $query = clone $this;
        $query->orders = [];
        $query->limit = null;
        $query->offset = null;
        $query->eagerLoad = [];
        return $query;
    }

    private function aggregate(string $function, string $column): mixed
    {
        [$sql, $bindings] = $this->aggregateStatement($function, $column);
        $row = $this->getConnection()->execute($sql, $bindings)->first();

        return $row['aggregate'] ?? null;
    }

    private function aggregateAsync(string $function, string $column): Future
    {
        return $this->selectAsync($this->aggregateStatement($function, $column))
            ->then(static fn(array $rows) => $rows[0]['aggregate'] ?? null);
    }

    /**
     * @param array{0: string, 1: array} $statement
     */
    private function selectAsync(array $statement): Future
    {
        return AsyncConnection::select($this->getConnection(), $statement[0], $statement[1]);
    }

    // ========================================
    // Statements shared by the sync and async methods
    // ========================================

    /**
     * @return array{0: string, 1: array}
     */
    private function countStatement(string $column): array
    {
        $query = $this->forAggregate();
        $expression = $column === '*' ? 'COUNT(*)' : 'COUNT(' . $this->grammar()->wrap(Grammar::assertReference($column)) . ')';

        if ($query->groups !== [] || $query->distinct || $query->havings !== []) {
            if ($query->columns === [] && $query->groups !== []) {
                $query->columns = $query->groups;
            }
            [$inner, $bindings] = $query->compileSelect();
            return ["SELECT {$expression} AS aggregate FROM ({$inner}) AS miko_count", $bindings];
        }

        $query->columns = [['raw' => "{$expression} AS aggregate", 'bindings' => []]];
        return $query->compileSelect();
    }

    /**
     * @return array{0: string, 1: array}
     */
    private function existsStatement(): array
    {
        $query = $this->forAggregate();
        $query->columns = [['raw' => '1 AS miko_exists', 'bindings' => []]];
        $query->limit = 1;

        return $query->compileSelect();
    }

    /**
     * @return array{0: string, 1: array}
     */
    private function aggregateStatement(string $function, string $column): array
    {
        $query = $this->forAggregate();
        $wrapped = $this->grammar()->wrap(Grammar::assertReference($column));
        $query->columns = [['raw' => "{$function}({$wrapped}) AS aggregate", 'bindings' => []]];

        return $query->compileSelect();
    }

    /**
     * @return array{0: string, 1: array}
     */
    private function valueStatement(string $column): array
    {
        $query = clone $this;
        $query->columns = [Grammar::assertColumn($column)];
        $query->limit = 1;
        $query->eagerLoad = [];

        return $query->compileSelect();
    }

    /**
     * @return array{0: string, 1: array}
     */
    private function pluckStatement(string $column, ?string $key): array
    {
        $query = clone $this;
        $query->columns = $key === null
            ? [Grammar::assertColumn($column)]
            : [Grammar::assertColumn($column), Grammar::assertColumn($key)];
        $query->eagerLoad = [];

        return $query->compileSelect();
    }

    private function pluckRows(array $rows, string $column, ?string $key): array
    {
        $valueKey = $this->resultKey($column);
        return $key === null
            ? array_column($rows, $valueKey)
            : array_column($rows, $valueKey, $this->resultKey($key));
    }

    private function paginationResult(array $items, int $total, int $perPage, int $page): array
    {
        $offset = ($page - 1) * $perPage;

        return [
            'data' => $items,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'from' => $items !== [] ? $offset + 1 : 0,
            'to' => $items !== [] ? $offset + count($items) : 0,
        ];
    }

    private function numeric(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return $value + 0;
        }
        return null;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            throw new DatabaseException('Arrays/objects cannot be bound in where(); use whereIn() for lists.');
        }
        return is_object($value) ? (string) $value : $value;
    }

    private function boolean(string $boolean): string
    {
        $b = strtoupper(trim($boolean));
        if ($b !== 'AND' && $b !== 'OR') {
            throw new DatabaseException("Invalid boolean: {$boolean}");
        }
        return $b;
    }

    /**
     * Result array key of a select item: "users.Name" -> Name, "x as y" -> y
     */
    private function resultKey(string $column): string
    {
        if (preg_match('/\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', trim($column), $m)) {
            return $m[1];
        }
        $parts = explode('.', trim($column));
        return end($parts);
    }

    private function createdAtColumn(): string
    {
        return method_exists($this->model, 'getCreatedAtColumn') ? $this->model->getCreatedAtColumn() : 'CreatedDate';
    }

    private function assertSoftDeletes(): void
    {
        if (!$this->model->usesSoftDeletes()) {
            throw new DatabaseException(get_class($this->model) . ' does not use SoftDeletes.');
        }
    }

    private function assertNoJoins(string $operation): void
    {
        if ($this->joins !== []) {
            throw new DatabaseException("{$operation}() with joins is not supported; filter with whereIn()/whereRaw() instead.");
        }
    }
}
