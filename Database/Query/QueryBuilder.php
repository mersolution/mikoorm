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

use Closure;
use Miko\Core\Async\Future;
use Miko\Database\Async\AsyncConnection;
use Miko\Database\Connection;
use Miko\Database\ConnectionInterface;
use Miko\Database\Exceptions\DatabaseException;

/**
 * Table query builder (no models) - DB::table('Users')->where(...)->get()
 *
 * - Column / table names and operators are validated and quoted
 * - Values are bound as named parameters, so the order of where()/having()/
 *   selectRaw() calls never matters
 * - join($table, $condition) takes a raw SQL condition (use addJoin()/joinOn()
 *   for validated column joins)
 */
class QueryBuilder implements QueryBuilderInterface
{
    protected ConnectionInterface $connection;
    protected string $table = '';
    protected ?string $tableAlias = null;
    protected array $columns = [];
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
    protected string $paramPrefix;

    private static int $instances = 0;

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
        $this->paramPrefix = ':q' . (++self::$instances) . '_';
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    protected function grammar(): Grammar
    {
        return $this->connection->getGrammar();
    }

    // ========================================
    // Select / from / join
    // ========================================

    /**
     * select('Id', 'u.Name as UserName', 'COUNT(*) as Total') - expressions go to selectRaw()
     */
    public function select(array|string ...$columns): self
    {
        $list = [];
        foreach ($columns as $column) {
            array_push($list, ...(array) $column);
        }

        $this->columns = [];
        foreach ($list === [] ? ['*'] : $list as $column) {
            $this->columns[] = $this->grammar()->wrap((string) $column);
        }

        return $this;
    }

    /**
     * Raw select expression; values through $bindings ("?" placeholders)
     */
    public function selectRaw(string $expression, array $bindings = []): self
    {
        $this->columns[] = $this->convertPlaceholders($expression, $bindings);
        return $this;
    }

    public function from(string $table, ?string $alias = null): self
    {
        $this->table = Grammar::assertTable($table);
        $this->tableAlias = $alias !== null ? Grammar::assertIdentifier($alias, 'table alias') : null;
        return $this;
    }

    /**
     * Join with a raw SQL condition (never put user input in $condition)
     */
    public function join(string $table, string $condition, string $type = 'INNER'): self
    {
        $this->joins[] = [
            'type' => Grammar::joinType($type),
            'table' => $this->grammar()->wrapTable($table),
            'condition' => $condition,
        ];
        return $this;
    }

    public function leftJoin(string $table, string $condition): self
    {
        return $this->join($table, $condition, 'LEFT');
    }

    public function rightJoin(string $table, string $condition): self
    {
        return $this->join($table, $condition, 'RIGHT');
    }

    public function crossJoin(string $table): self
    {
        $this->joins[] = ['type' => 'CROSS', 'table' => $this->grammar()->wrapTable($table), 'condition' => null];
        return $this;
    }

    /**
     * Join on two validated columns: joinOn('Orders o', 'o.UserId', '=', 'u.Id')
     */
    public function joinOn(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $grammar = $this->grammar();
        return $this->join(
            $table,
            $grammar->wrap(Grammar::assertReference($first)) . ' ' . Grammar::comparison($operator) . ' ' . $grammar->wrap(Grammar::assertReference($second)),
            $type
        );
    }

    /**
     * Join main.mainKey = table.targetKey: addJoin('tblcity', 'CityPlateCode', 'PlateCode')
     */
    public function addJoin(string $table, string $mainKey, string $targetKey, string $type = 'INNER'): self
    {
        $main = $this->tableAlias ?? $this->table;
        $tableName = preg_split('/\s+/', trim($table))[0];
        $target = preg_split('/\s+/', trim($table));
        $targetName = count($target) > 1 ? end($target) : $tableName;

        return $this->joinOn($table, "{$main}.{$mainKey}", '=', "{$targetName}.{$targetKey}", $type);
    }

    public function addLeftJoin(string $table, string $mainKey, string $targetKey): self
    {
        return $this->addJoin($table, $mainKey, $targetKey, 'LEFT');
    }

    public function addRightJoin(string $table, string $mainKey, string $targetKey): self
    {
        return $this->addJoin($table, $mainKey, $targetKey, 'RIGHT');
    }

    // ========================================
    // Where
    // ========================================

    /**
     * where('Name', 'x') / where('Age', '>', 5) / where(['A' => 1]) / where(fn($q) => ...)
     */
    public function where(mixed $column, mixed $operator = '=', mixed $value = null): self
    {
        if (func_num_args() === 2 && !($column instanceof Closure) && !is_array($column)) {
            [$operator, $value] = ['=', $operator];
        }

        return $this->addWhere('AND', $column, $operator, $value);
    }

    public function orWhere(mixed $column, mixed $operator = '=', mixed $value = null): self
    {
        if (func_num_args() === 2 && !($column instanceof Closure) && !is_array($column)) {
            [$operator, $value] = ['=', $operator];
        }

        return $this->addWhere('OR', $column, $operator, $value);
    }

    private function addWhere(string $boolean, mixed $column, mixed $operator, mixed $value): self
    {
        if ($column instanceof Closure) {
            return $this->addNestedWhere($column, $boolean);
        }

        if (is_array($column)) {
            return $this->addNestedWhere(function (self $q) use ($column) {
                foreach ($column as $key => $val) {
                    $q->where($key, '=', $val);
                }
            }, $boolean);
        }

        $operator = Grammar::operator((string) $operator);
        $wrapped = $this->grammar()->wrap(Grammar::assertReference((string) $column));

        if ($value === null && in_array($operator, ['=', '!=', '<>'], true)) {
            return $this->pushWhere($boolean, $wrapped . ($operator === '=' ? ' IS NULL' : ' IS NOT NULL'));
        }

        return $this->pushWhere($boolean, "{$wrapped} {$operator} " . $this->bind($value));
    }

    private function addNestedWhere(Closure $callback, string $boolean): self
    {
        $nested = new static($this->connection);
        $nested->paramPrefix = $this->paramPrefix;
        $nested->paramCounter = $this->paramCounter;
        $callback($nested);
        $this->paramCounter = $nested->paramCounter;

        if ($nested->wheres === []) {
            return $this;
        }

        $this->bindings = array_merge($this->bindings, $nested->bindings);
        return $this->pushWhere($boolean, '(' . $nested->compileWheres() . ')');
    }

    public function whereIn(string $column, array $values): self
    {
        return $this->addIn('AND', $column, $values, false);
    }

    public function whereNotIn(string $column, array $values): self
    {
        return $this->addIn('AND', $column, $values, true);
    }

    public function orWhereIn(string $column, array $values): self
    {
        return $this->addIn('OR', $column, $values, false);
    }

    public function orWhereNotIn(string $column, array $values): self
    {
        return $this->addIn('OR', $column, $values, true);
    }

    private function addIn(string $boolean, string $column, array $values, bool $not): self
    {
        if ($values === []) {
            return $this->pushWhere($boolean, $not ? '1 = 1' : '1 = 0');
        }

        $params = array_map(fn($v) => $this->bind($v), array_values($values));
        $wrapped = $this->grammar()->wrap(Grammar::assertReference($column));

        return $this->pushWhere($boolean, $wrapped . ($not ? ' NOT IN (' : ' IN (') . implode(', ', $params) . ')');
    }

    public function whereNull(string $column): self
    {
        return $this->pushWhere('AND', $this->grammar()->wrap(Grammar::assertReference($column)) . ' IS NULL');
    }

    public function whereNotNull(string $column): self
    {
        return $this->pushWhere('AND', $this->grammar()->wrap(Grammar::assertReference($column)) . ' IS NOT NULL');
    }

    public function orWhereNull(string $column): self
    {
        return $this->pushWhere('OR', $this->grammar()->wrap(Grammar::assertReference($column)) . ' IS NULL');
    }

    public function orWhereNotNull(string $column): self
    {
        return $this->pushWhere('OR', $this->grammar()->wrap(Grammar::assertReference($column)) . ' IS NOT NULL');
    }

    /**
     * Raw condition with "?" (or :named) placeholders
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        return $this->pushWhere('AND', $this->convertPlaceholders($sql, $bindings));
    }

    public function orWhereRaw(string $sql, array $bindings = []): self
    {
        return $this->pushWhere('OR', $this->convertPlaceholders($sql, $bindings));
    }

    /**
     * Contains search; wildcards in $value are literal unless $wrapWithPercent is false
     */
    public function whereLike(string $column, string $value, bool $wrapWithPercent = true): self
    {
        return $this->addLike('AND', $column, $value, $wrapWithPercent, false);
    }

    public function orWhereLike(string $column, string $value, bool $wrapWithPercent = true): self
    {
        return $this->addLike('OR', $column, $value, $wrapWithPercent, false);
    }

    public function whereNotLike(string $column, string $value, bool $wrapWithPercent = true): self
    {
        return $this->addLike('AND', $column, $value, $wrapWithPercent, true);
    }

    /**
     * Prefix search (index friendly): Name LIKE 'Al%'
     */
    public function whereStartsWith(string $column, string $value): self
    {
        $grammar = $this->grammar();
        return $this->pushWhere(
            'AND',
            $grammar->wrap(Grammar::assertReference($column)) . ' LIKE ' . $this->bind($grammar->likeEscape($value) . '%') . $grammar->likeEscapeClause()
        );
    }

    private function addLike(string $boolean, string $column, string $value, bool $wrap, bool $not): self
    {
        $grammar = $this->grammar();
        $param = $this->bind($wrap ? '%' . $grammar->likeEscape($value) . '%' : $value);

        return $this->pushWhere(
            $boolean,
            $grammar->wrap(Grammar::assertReference($column)) . ($not ? ' NOT LIKE ' : ' LIKE ') . $param . ($wrap ? $grammar->likeEscapeClause() : '')
        );
    }

    public function whereBetween(string $column, array $values): self
    {
        return $this->addBetween($column, $values, false);
    }

    public function whereNotBetween(string $column, array $values): self
    {
        return $this->addBetween($column, $values, true);
    }

    private function addBetween(string $column, array $values, bool $not): self
    {
        if (count($values) !== 2) {
            throw new DatabaseException('whereBetween requires exactly 2 values');
        }

        $values = array_values($values);
        $wrapped = $this->grammar()->wrap(Grammar::assertReference($column));

        return $this->pushWhere('AND', $wrapped . ($not ? ' NOT BETWEEN ' : ' BETWEEN ') . $this->bind($values[0]) . ' AND ' . $this->bind($values[1]));
    }

    /**
     * whereDate('Created', '=', '2026-01-31') - plain dates use index friendly ranges
     */
    public function whereDate(string $column, string $operator, ?string $value = null): self
    {
        if (func_num_args() === 2) {
            [$operator, $value] = ['=', $operator];
        }
        return $this->addDatePart('date', $column, $operator, $value);
    }

    public function whereMonth(string $column, string $operator, ?int $value = null): self
    {
        if (func_num_args() === 2) {
            [$operator, $value] = ['=', (int) $operator];
        }
        return $this->addDatePart('month', $column, $operator, $value);
    }

    public function whereYear(string $column, string $operator, ?int $value = null): self
    {
        if (func_num_args() === 2) {
            [$operator, $value] = ['=', (int) $operator];
        }
        return $this->addDatePart('year', $column, $operator, $value);
    }

    public function whereDay(string $column, string $operator, ?int $value = null): self
    {
        if (func_num_args() === 2) {
            [$operator, $value] = ['=', (int) $operator];
        }
        return $this->addDatePart('day', $column, $operator, $value);
    }

    private function addDatePart(string $part, string $column, string $operator, mixed $value): self
    {
        $operator = Grammar::comparison($operator);
        $grammar = $this->grammar();
        $wrapped = $grammar->wrap(Grammar::assertReference($column));

        $range = null;
        if ($part === 'date' && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $range = [$value, (new \DateTimeImmutable($value))->modify('+1 day')->format('Y-m-d')];
        } elseif ($part === 'year' && is_int($value) && $value > 0) {
            $range = [sprintf('%04d-01-01', $value), sprintf('%04d-01-01', $value + 1)];
        }

        if ($range !== null && $operator !== '!=' && $operator !== '<>') {
            [$start, $end] = $range;
            $sql = match ($operator) {
                '=' => "({$wrapped} >= " . $this->bind($start) . " AND {$wrapped} < " . $this->bind($end) . ')',
                '<' => "{$wrapped} < " . $this->bind($start),
                '<=' => "{$wrapped} < " . $this->bind($end),
                '>' => "{$wrapped} >= " . $this->bind($end),
                '>=' => "{$wrapped} >= " . $this->bind($start),
            };
            return $this->pushWhere('AND', $sql);
        }

        return $this->pushWhere('AND', $grammar->datePart($part, $wrapped) . " {$operator} " . $this->bind($value));
    }

    public function whereColumn(string $first, string $operator, string $second): self
    {
        $grammar = $this->grammar();
        return $this->pushWhere(
            'AND',
            $grammar->wrap(Grammar::assertReference($first)) . ' ' . Grammar::comparison($operator) . ' ' . $grammar->wrap(Grammar::assertReference($second))
        );
    }

    /**
     * Apply the callback only when the condition is truthy
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

    // ========================================
    // Order / group / having / limit
    // ========================================

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->orderBy[] = $this->grammar()->wrap(Grammar::assertReference($column)) . ' ' . Grammar::direction($direction);
        return $this;
    }

    public function orderByRaw(string $sql, array $bindings = []): self
    {
        $this->orderBy[] = $this->convertPlaceholders($sql, $bindings);
        return $this;
    }

    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'DESC');
    }

    public function latest(string $column = 'CreatedDate'): self
    {
        return $this->orderBy($column, 'DESC');
    }

    public function oldest(string $column = 'CreatedDate'): self
    {
        return $this->orderBy($column, 'ASC');
    }

    public function inRandomOrder(): self
    {
        $this->orderBy[] = $this->grammar()->random();
        return $this;
    }

    public function take(int $value): self
    {
        return $this->limit($value);
    }

    public function skip(int $value): self
    {
        return $this->offset($value);
    }

    public function groupBy(array|string ...$columns): self
    {
        foreach ($columns as $column) {
            foreach ((array) $column as $c) {
                $this->groupBy[] = $this->grammar()->wrap(Grammar::assertReference((string) $c));
            }
        }
        return $this;
    }

    /**
     * having('Total', '>', 5) / having('COUNT(*)', '>', 1)
     */
    public function having(string $column, string $operator, mixed $value): self
    {
        $this->having[] = $this->grammar()->wrap($column) . ' ' . Grammar::operator($operator) . ' ' . $this->bind($value);
        return $this;
    }

    public function havingRaw(string $sql, array $bindings = []): self
    {
        $this->having[] = $this->convertPlaceholders($sql, $bindings);
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);
        return $this;
    }

    public function distinct(): self
    {
        $this->distinct = true;
        return $this;
    }

    // ========================================
    // Execution
    // ========================================

    /**
     * Run raw SQL ("?" or :named placeholders)
     */
    public function rawQuery(string $sql, array $bindings = []): array
    {
        return $this->connection->execute($sql, $bindings)->all();
    }

    public function get(): array
    {
        [$sql, $bindings] = $this->selectStatement();
        return $this->connection->execute($sql, $bindings)->all();
    }

    public function first(): ?array
    {
        $query = clone $this;
        $query->limit = 1;
        return $query->get()[0] ?? null;
    }

    public function count(): int
    {
        [$sql, $bindings] = $this->countStatement();
        $row = $this->connection->execute($sql, $bindings)->first();
        return (int) ($row['aggregate'] ?? 0);
    }

    public function exists(): bool
    {
        [$sql, $bindings] = $this->existsStatement();
        return $this->connection->execute($sql, $bindings)->first() !== null;
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

    protected function aggregate(string $function, string $column): mixed
    {
        [$sql, $bindings] = $this->aggregateStatement($function, $column);
        $row = $this->connection->execute($sql, $bindings)->first();
        return $row['aggregate'] ?? null;
    }

    // ========================================
    // Async execution
    // ========================================
    //
    // Same results as the methods above, as a Future. MySQL / MariaDB, PostgreSQL and
    // SQL Server run them in parallel on extra connections; SQLite and queries inside
    // a transaction run right away on the main connection.

    /**
     * @return Future<array>
     */
    public function getAsync(): Future
    {
        return $this->runAsync($this->selectStatement());
    }

    /**
     * @return Future<?array>
     */
    public function firstAsync(): Future
    {
        $query = clone $this;
        $query->limit = 1;
        return $query->getAsync()->then(static fn(array $rows) => $rows[0] ?? null);
    }

    public function findAsync(int|string $id, string $primaryKey = 'Id'): Future
    {
        return (clone $this)->where($primaryKey, '=', $id)->firstAsync();
    }

    /**
     * @return Future<int>
     */
    public function countAsync(): Future
    {
        return $this->runAsync($this->countStatement())->then(static fn(array $rows) => (int) ($rows[0]['aggregate'] ?? 0));
    }

    /**
     * @return Future<bool>
     */
    public function existsAsync(): Future
    {
        return $this->runAsync($this->existsStatement())->then(static fn(array $rows) => $rows !== []);
    }

    public function sumAsync(string $column): Future
    {
        return $this->aggregateAsync('SUM', $column)->then(fn($value) => $this->numeric($value) ?? 0);
    }

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
        return (clone $this)->select($column)->firstAsync()
            ->then(static fn(?array $row) => $row === null ? null : ($row[$key] ?? reset($row)));
    }

    /**
     * @return Future<array>
     */
    public function pluckAsync(string $column, ?string $key = null): Future
    {
        $valueKey = $this->resultKey($column);
        $indexKey = $key === null ? null : $this->resultKey($key);

        return (clone $this)->select($key === null ? [$column] : [$column, $key])->getAsync()
            ->then(static fn(array $rows) => $indexKey === null ? array_column($rows, $valueKey) : array_column($rows, $valueKey, $indexKey));
    }

    /**
     * Count and page run in parallel
     *
     * @return Future<array{data: array, pagination: array}>
     */
    public function paginateAsync(int $page, int $perPage = 50): Future
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 500));

        $query = clone $this;
        $query->limit = $perPage;
        $query->offset = ($page - 1) * $perPage;

        return Future::all(['total' => $this->countAsync(), 'data' => $query->getAsync()])
            ->then(fn(array $r) => $this->paginationResult($r['data'], $r['total'], $page, $perPage));
    }

    protected function aggregateAsync(string $function, string $column): Future
    {
        return $this->runAsync($this->aggregateStatement($function, $column))
            ->then(static fn(array $rows) => $rows[0]['aggregate'] ?? null);
    }

    /**
     * @param array{0: string, 1: array} $statement
     */
    protected function runAsync(array $statement): Future
    {
        return AsyncConnection::select($this->connection, $statement[0], $statement[1]);
    }

    // ========================================
    // Statements shared by the sync and async methods
    // ========================================

    /**
     * @return array{0: string, 1: array}
     */
    protected function selectStatement(): array
    {
        return [$this->toSql(), $this->getBindings()];
    }

    /**
     * @return array{0: string, 1: array}
     */
    protected function countStatement(): array
    {
        $query = clone $this;
        $query->orderBy = [];
        $query->limit = null;
        $query->offset = null;

        if ($query->groupBy !== [] || $query->distinct || $query->having !== []) {
            if (($query->columns === [] || $query->columns === ['*']) && $query->groupBy !== []) {
                $query->columns = $query->groupBy;
            }
            return ['SELECT COUNT(*) AS aggregate FROM (' . $query->toSql() . ') AS miko_count', $query->getBindings()];
        }

        $query->columns = ['COUNT(*) AS aggregate'];
        return [$query->toSql(), $query->getBindings()];
    }

    /**
     * @return array{0: string, 1: array}
     */
    protected function existsStatement(): array
    {
        $query = clone $this;
        $query->orderBy = [];
        $query->columns = ['1 AS miko_exists'];
        $query->limit = 1;
        $query->offset = null;

        return [$query->toSql(), $query->getBindings()];
    }

    /**
     * @return array{0: string, 1: array}
     */
    protected function aggregateStatement(string $function, string $column): array
    {
        $query = clone $this;
        $query->orderBy = [];
        $query->limit = null;
        $query->offset = null;
        $query->columns = ["{$function}(" . $this->grammar()->wrap(Grammar::assertReference($column)) . ') AS aggregate'];

        return [$query->toSql(), $query->getBindings()];
    }

    private function paginationResult(array $data, int $total, int $page, int $perPage): array
    {
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        return [
            'data' => $data,
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $data !== [] ? $offset + 1 : 0,
                'to' => $data !== [] ? $offset + count($data) : 0,
                'has_more' => $page < $lastPage,
            ],
        ];
    }

    // ========================================
    // Writes
    // ========================================

    public function insert(array $data): bool
    {
        if ($data === []) {
            throw new DatabaseException('Insert data cannot be empty');
        }

        $grammar = $this->grammar();
        $columns = [];
        $params = [];
        $bindings = [];

        foreach ($data as $column => $value) {
            $columns[] = $grammar->wrap(Grammar::assertReference((string) $column));
            $param = $this->createParam();
            $params[] = $param;
            $bindings[$param] = $value;
        }

        $this->connection->execute(
            'INSERT INTO ' . $this->wrappedTable(false) . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $params) . ')',
            $bindings
        );

        return true;
    }

    public function update(array $data): int
    {
        if ($data === []) {
            throw new DatabaseException('Update data cannot be empty');
        }

        $grammar = $this->grammar();
        $sets = [];
        $bindings = [];

        foreach ($data as $column => $value) {
            $param = $this->createParam();
            $sets[] = $grammar->wrap(Grammar::assertReference((string) $column)) . " = {$param}";
            $bindings[$param] = $value;
        }

        $sql = 'UPDATE ' . $this->wrappedTable(false) . ' SET ' . implode(', ', $sets);
        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . $this->compileWheres();
        }

        return $this->connection->execute($sql, array_merge($bindings, $this->bindings))->count();
    }

    public function delete(): int
    {
        $sql = 'DELETE FROM ' . $this->wrappedTable(false);
        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . $this->compileWheres();
        }

        return $this->connection->execute($sql, $this->bindings)->count();
    }

    /**
     * Atomic increment: SET col = col + :amount
     */
    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        $grammar = $this->grammar();
        $wrapped = $grammar->wrap(Grammar::assertReference($column));
        $amountParam = $this->createParam();
        $bindings = [$amountParam => $amount];
        $sets = ["{$wrapped} = {$wrapped} + {$amountParam}"];

        foreach ($extra as $col => $value) {
            $param = $this->createParam();
            $sets[] = $grammar->wrap(Grammar::assertReference((string) $col)) . " = {$param}";
            $bindings[$param] = $value;
        }

        $sql = 'UPDATE ' . $this->wrappedTable(false) . ' SET ' . implode(', ', $sets);
        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . $this->compileWheres();
        }

        return $this->connection->execute($sql, array_merge($bindings, $this->bindings))->count();
    }

    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->increment($column, -$amount, $extra);
    }

    /**
     * Update the row matching $attributes, insert it when missing
     */
    public function updateOrInsert(array $attributes, array $values = []): bool
    {
        $match = (clone $this)->clearWheres()->where($attributes);

        if ($match->exists()) {
            if ($values !== []) {
                (clone $match)->update($values);
            }
            return true;
        }

        return (clone $this)->clearWheres()->insert(array_merge($attributes, $values));
    }

    /**
     * Multi-row insert, chunked to the driver parameter limit
     */
    public function insertBatch(array $records): bool
    {
        $records = array_values(array_filter($records, 'is_array'));
        if ($records === []) {
            return false;
        }

        $grammar = $this->grammar();
        $columns = array_keys($records[0]);
        $wrapped = implode(', ', array_map(fn($c) => $grammar->wrap(Grammar::assertReference((string) $c)), $columns));
        $maxParameters = $this->connection instanceof Connection ? $this->connection->maxParameters() : $grammar->maxParameters();
        $chunkSize = max(1, intdiv($maxParameters, max(1, count($columns))));

        foreach (array_chunk($records, $chunkSize) as $chunk) {
            $rows = [];
            $bindings = [];
            foreach ($chunk as $record) {
                $params = [];
                foreach ($columns as $column) {
                    $param = $this->createParam();
                    $params[] = $param;
                    $bindings[$param] = $record[$column] ?? null;
                }
                $rows[] = '(' . implode(', ', $params) . ')';
            }

            $this->connection->execute(
                'INSERT INTO ' . $this->wrappedTable(false) . " ({$wrapped}) VALUES " . implode(', ', $rows),
                $bindings
            );
        }

        return true;
    }

    // ========================================
    // SQL
    // ========================================

    public function toSql(): string
    {
        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '')
            . implode(', ', $this->columns === [] ? ['*'] : $this->columns)
            . ' FROM ' . $this->wrappedTable(true);

        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} JOIN {$join['table']}";
            if ($join['condition'] !== null) {
                $sql .= " ON {$join['condition']}";
            }
        }

        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . $this->compileWheres();
        }

        if ($this->groupBy !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }

        if ($this->having !== []) {
            $sql .= ' HAVING ' . implode(' AND ', $this->having);
        }

        if ($this->orderBy !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }

        return $sql . $this->grammar()->compileLimit($this->limit, $this->offset, $this->orderBy !== []);
    }

    /**
     * Named bindings (":q1_0" => value)
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    public function clone(): self
    {
        return clone $this;
    }

    /**
     * Reset for reuse
     */
    public function clear(): self
    {
        $this->table = '';
        $this->tableAlias = null;
        $this->columns = [];
        $this->joins = [];
        $this->wheres = [];
        $this->bindings = [];
        $this->orderBy = [];
        $this->groupBy = [];
        $this->having = [];
        $this->limit = null;
        $this->offset = null;
        $this->distinct = false;
        return $this;
    }

    public function lastInsertId(): int|string
    {
        $id = $this->connection->lastInsertId();
        return is_numeric($id) ? (int) $id : $id;
    }

    public function insertGetId(array $data): int|string
    {
        $this->insert($data);
        return $this->lastInsertId();
    }

    public function find(int|string $id, string $primaryKey = 'Id'): ?array
    {
        return (clone $this)->where($primaryKey, '=', $id)->first();
    }

    public function findOrFail(int|string $id, string $primaryKey = 'Id'): array
    {
        return $this->find($id, $primaryKey) ?? throw new DatabaseException("Record not found with {$primaryKey} = {$id}");
    }

    public function firstOrFail(): array
    {
        return $this->first() ?? throw new DatabaseException('No records found');
    }

    public function paginate(int $page, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 500));

        $total = $this->count();

        $query = clone $this;
        $query->limit = $perPage;
        $query->offset = ($page - 1) * $perPage;

        return $this->paginationResult($query->get(), $total, $page, $perPage);
    }

    public function value(string $column): mixed
    {
        $row = (clone $this)->select($column)->first();
        if ($row === null) {
            return null;
        }
        return $row[$this->resultKey($column)] ?? reset($row);
    }

    public function pluck(string $column, ?string $key = null): array
    {
        $query = (clone $this)->select($key === null ? [$column] : [$column, $key]);
        $rows = $query->get();

        return $key === null
            ? array_column($rows, $this->resultKey($column))
            : array_column($rows, $this->resultKey($column), $this->resultKey($key));
    }

    public function chunk(int $count, callable $callback): bool
    {
        $count = max(1, $count);
        $page = 1;

        do {
            $query = clone $this;
            $query->limit = $count;
            $query->offset = ($page - 1) * $count;
            $results = $query->get();
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

    // ========================================
    // Internals
    // ========================================

    protected function compileWheres(): string
    {
        $sql = '';
        foreach ($this->wheres as $where) {
            $sql .= ($sql === '' ? '' : ' ' . $where['boolean'] . ' ') . $where['sql'];
        }
        return $sql;
    }

    private function pushWhere(string $boolean, string $sql): self
    {
        $this->wheres[] = ['boolean' => $boolean, 'sql' => $sql];
        return $this;
    }

    private function clearWheres(): self
    {
        $this->wheres = [];
        $this->bindings = [];
        return $this;
    }

    protected function createParam(): string
    {
        return $this->paramPrefix . $this->paramCounter++;
    }

    /**
     * Bind a value and return its placeholder
     */
    protected function bind(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        } elseif ($value instanceof \BackedEnum) {
            $value = $value->value;
        } elseif (is_array($value)) {
            throw new DatabaseException('Arrays cannot be bound; use whereIn() for lists.');
        }

        $param = $this->createParam();
        $this->bindings[$param] = $value;
        return $param;
    }

    /**
     * Replace "?" placeholders (outside string literals) with named ones; named
     * bindings (['id' => 5] for :id) are added as they are
     */
    protected function convertPlaceholders(string $sql, array $bindings): string
    {
        $positional = [];
        foreach ($bindings as $key => $value) {
            if (is_string($key)) {
                $this->bindings[$key[0] === ':' ? $key : ':' . $key] = $value;
            } else {
                $positional[] = $value;
            }
        }

        $index = 0;
        $converted = preg_replace_callback(
            "/'(?:[^'\\\\]|\\\\.|'')*'|\"(?:[^\"\\\\]|\\\\.)*\"|\\?/s",
            function (array $m) use (&$index, $positional) {
                if ($m[0] !== '?') {
                    return $m[0];
                }
                if (!array_key_exists($index, $positional)) {
                    throw new DatabaseException('Not enough bindings for the raw SQL placeholders.');
                }
                return $this->bind($positional[$index++]);
            },
            $sql
        );

        if ($index !== count($positional)) {
            throw new DatabaseException('More bindings than "?" placeholders in the raw SQL.');
        }

        return $converted;
    }

    private function wrappedTable(bool $withAlias): string
    {
        if ($this->table === '') {
            throw new DatabaseException('No table specified; call from() first.');
        }

        $grammar = $this->grammar();
        $sql = $grammar->wrapTable($this->table);
        if ($withAlias && $this->tableAlias !== null) {
            $sql .= ' ' . $grammar->quote($this->tableAlias);
        }
        return $sql;
    }

    private function resultKey(string $column): string
    {
        if (preg_match('/\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', trim($column), $m)) {
            return $m[1];
        }
        $parts = explode('.', trim($column));
        return end($parts);
    }

    private function numeric(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        return is_string($value) && is_numeric($value) ? $value + 0 : null;
    }
}
