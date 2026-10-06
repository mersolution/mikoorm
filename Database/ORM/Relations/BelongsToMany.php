<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\ORM\Relations;

use Miko\Core\Async\Future;
use Miko\Database\Exceptions\DatabaseException;
use Miko\Database\ORM\Model;
use Miko\Database\ORM\Transaction;
use Miko\Database\Query\Grammar;

/**
 * BelongsToMany Relation (N:M through a pivot table)
 *
 * Pivot values are available as $model->pivot (array).
 */
class BelongsToMany extends Relation
{
    protected string $table;
    protected string $foreignPivotKey;
    protected string $relatedPivotKey;
    protected string $parentKey;
    protected string $relatedKey;
    protected array $pivotColumns = [];

    public function __construct(
        Model $parent,
        string $related,
        string $pivotTable,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null
    ) {
        parent::__construct($parent, $related);

        Grammar::assertTable($pivotTable);
        $this->table = $pivotTable;
        $this->foreignPivotKey = Grammar::assertIdentifier($foreignPivotKey ?? $parent->getForeignKeyName(), 'pivot key');
        $this->relatedPivotKey = Grammar::assertIdentifier($relatedPivotKey ?? $this->relatedModel->getForeignKeyName(), 'pivot key');
        $this->parentKey = $parentKey ?? $parent->getPrimaryKey();
        $this->relatedKey = $relatedKey ?? $this->relatedModel->getPrimaryKey();

        $this->query->join(
            $this->table,
            $related::getTable() . '.' . $this->relatedKey,
            '=',
            $this->table . '.' . $this->relatedPivotKey
        );

        $this->refreshSelect();
    }

    /**
     * Also read these pivot columns into $model->pivot
     */
    public function withPivot(string ...$columns): static
    {
        foreach ($columns as $column) {
            $this->pivotColumns[] = Grammar::assertIdentifier($column, 'pivot column');
        }
        $this->pivotColumns = array_values(array_unique($this->pivotColumns));
        $this->refreshSelect();
        return $this;
    }

    private function refreshSelect(): void
    {
        $columns = [$this->related::getTable() . '.*'];
        $pivot = array_unique(array_merge([$this->foreignPivotKey, $this->relatedPivotKey], $this->pivotColumns));

        foreach ($pivot as $column) {
            $columns[] = "{$this->table}.{$column} as pivot_{$column}";
        }

        $this->query->select($columns);
    }

    protected function addConstraints(): void
    {
        $value = $this->parent->getAttributeValue($this->parentKey);

        if ($value === null) {
            $this->query->whereRaw('1 = 0');
            return;
        }

        $this->query->where("{$this->table}.{$this->foreignPivotKey}", '=', $value);
    }

    public function addEagerConstraints(array $models): void
    {
        $this->constrained = true;
        $keys = $this->collectKeys($models, $this->parentKey);

        if ($keys === []) {
            $this->eagerEmpty = true;
            return;
        }

        $this->query->whereIn("{$this->table}.{$this->foreignPivotKey}", $keys);
    }

    /**
     * @return Model[]
     */
    public function getResults(): array
    {
        if ($this->parent->getAttributeValue($this->parentKey) === null) {
            return [];
        }

        return $this->get();
    }

    public function get(): array
    {
        $this->constrain();
        return $this->hydratePivot($this->query->get());
    }

    public function first(): ?Model
    {
        $this->constrain();
        $model = $this->query->first();
        return $model === null ? null : $this->hydratePivot([$model])[0];
    }

    public function getEager(): array
    {
        return $this->eagerEmpty ? [] : $this->hydratePivot($this->query->get());
    }

    public function getAsync(): Future
    {
        $this->constrain();
        return $this->query->getAsync()->then(fn(array $models) => $this->hydratePivot($models));
    }

    public function firstAsync(): Future
    {
        $this->constrain();
        return $this->query->firstAsync()->then(fn(?Model $model) => $model === null ? null : $this->hydratePivot([$model])[0]);
    }

    public function getEagerAsync(): Future
    {
        return $this->eagerEmpty
            ? Future::resolved([])
            : $this->query->getAsync()->then(fn(array $models) => $this->hydratePivot($models));
    }

    public function match(array $models, array $results, string $relation): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            $pivot = $result->getRelation('pivot') ?? [];
            $key = self::keyOf($pivot[$this->foreignPivotKey] ?? null);
            if ($key !== null) {
                $dictionary[$key][] = $result;
            }
        }

        foreach ($models as $model) {
            $key = self::keyOf($model->getAttributeValue($this->parentKey));
            $model->setRelation($relation, $key !== null ? ($dictionary[$key] ?? []) : []);
        }

        return $models;
    }

    /**
     * Move pivot_* columns from the attributes into $model->pivot
     */
    private function hydratePivot(array $models): array
    {
        foreach ($models as $model) {
            $model->setRelation('pivot', $model->pullAttributes('pivot_'));
        }
        return $models;
    }

    // ========================================
    // Pivot writes
    // ========================================

    /**
     * attach(5) / attach([1, 2]) / attach([1 => ['Role' => 'x']]) / attach($model, ['Extra' => 1])
     */
    public function attach(mixed $ids, array $attributes = []): void
    {
        $parentValue = $this->parentKeyValue();
        $records = [];

        foreach ($this->normalizeIds($ids) as $id => $extra) {
            $records[] = array_merge(
                [$this->foreignPivotKey => $parentValue, $this->relatedPivotKey => $id],
                $attributes,
                $extra
            );
        }

        $this->insertPivotRecords($records);
    }

    /**
     * Remove pivot rows (all when $ids is null). Returns affected rows.
     */
    public function detach(mixed $ids = null): int
    {
        $connection = $this->parent->getConnection();
        $grammar = $connection->getGrammar();
        $sql = 'DELETE FROM ' . $grammar->wrapTable($this->table) . ' WHERE ' . $grammar->wrap($this->foreignPivotKey) . ' = ?';
        $bindings = [$this->parentKeyValue()];

        if ($ids !== null) {
            $list = array_keys($this->normalizeIds($ids));
            if ($list === []) {
                return 0;
            }
            $sql .= ' AND ' . $grammar->wrap($this->relatedPivotKey) . ' IN (' . implode(', ', array_fill(0, count($list), '?')) . ')';
            array_push($bindings, ...$list);
        }

        return $connection->execute($sql, $bindings)->count();
    }

    /**
     * Make the pivot match $ids exactly (or only add when $detaching = false)
     *
     * @return array{attached: array, detached: array, updated: array}
     */
    public function sync(mixed $ids, bool $detaching = true): array
    {
        $wanted = $this->normalizeIds($ids);
        $connection = $this->parent->getConnection();

        return Transaction::run(function () use ($wanted, $detaching) {
            $current = array_map('strval', $this->currentRelatedIds());
            $changes = ['attached' => [], 'detached' => [], 'updated' => []];

            if ($detaching) {
                $wantedKeys = array_map('strval', array_keys($wanted));
                $detach = array_values(array_diff($current, $wantedKeys));
                if ($detach !== []) {
                    $this->detach($detach);
                    $changes['detached'] = $detach;
                }
            }

            $attach = [];
            foreach ($wanted as $id => $extra) {
                if (!in_array((string) $id, $current, true)) {
                    $attach[$id] = $extra;
                    $changes['attached'][] = $id;
                } elseif ($extra !== []) {
                    $this->updateExistingPivot($id, $extra);
                    $changes['updated'][] = $id;
                }
            }

            if ($attach !== []) {
                $this->attach($attach);
            }

            return $changes;
        }, $connection);
    }

    public function syncWithoutDetaching(mixed $ids): array
    {
        return $this->sync($ids, false);
    }

    /**
     * Update extra pivot columns of one related id
     */
    public function updateExistingPivot(mixed $id, array $attributes): int
    {
        if ($attributes === []) {
            return 0;
        }

        $connection = $this->parent->getConnection();
        $grammar = $connection->getGrammar();
        $sets = [];
        $bindings = [];

        foreach ($attributes as $column => $value) {
            $sets[] = $grammar->wrap(Grammar::assertIdentifier((string) $column, 'pivot column')) . ' = ?';
            $bindings[] = $value;
        }

        array_push($bindings, $this->parentKeyValue(), $id instanceof Model ? $id->getAttributeValue($this->relatedKey) : $id);

        return $connection->execute(
            'UPDATE ' . $grammar->wrapTable($this->table) . ' SET ' . implode(', ', $sets)
            . ' WHERE ' . $grammar->wrap($this->foreignPivotKey) . ' = ? AND ' . $grammar->wrap($this->relatedPivotKey) . ' = ?',
            $bindings
        )->count();
    }

    /**
     * Related ids currently in the pivot for this parent
     */
    public function currentRelatedIds(): array
    {
        $connection = $this->parent->getConnection();
        $grammar = $connection->getGrammar();

        $rows = $connection->execute(
            'SELECT ' . $grammar->wrap($this->relatedPivotKey) . ' FROM ' . $grammar->wrapTable($this->table)
            . ' WHERE ' . $grammar->wrap($this->foreignPivotKey) . ' = ?',
            [$this->parentKeyValue()]
        )->all();

        return array_column($rows, $this->relatedPivotKey);
    }

    private function insertPivotRecords(array $records): void
    {
        if ($records === []) {
            return;
        }

        $connection = $this->parent->getConnection();
        $grammar = $connection->getGrammar();
        $groups = [];

        foreach ($records as $record) {
            $groups[implode(',', array_keys($record))][] = $record;
        }

        foreach ($groups as $rows) {
            $columns = array_keys($rows[0]);
            foreach ($columns as $column) {
                Grammar::assertIdentifier((string) $column, 'pivot column');
            }

            $chunkSize = max(1, intdiv($connection instanceof \Miko\Database\Connection ? $connection->maxParameters() : 2000, count($columns)));
            $placeholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
            $prefix = 'INSERT INTO ' . $grammar->wrapTable($this->table) . ' (' . implode(', ', array_map(fn($c) => $grammar->wrap($c), $columns)) . ') VALUES ';

            foreach (array_chunk($rows, $chunkSize) as $chunk) {
                $values = [];
                foreach ($chunk as $row) {
                    array_push($values, ...array_values($row));
                }
                $connection->execute($prefix . implode(', ', array_fill(0, count($chunk), $placeholder)), $values);
            }
        }
    }

    /**
     * @return array<int|string, array> id => extra pivot attributes
     */
    private function normalizeIds(mixed $ids): array
    {
        if ($ids instanceof Model) {
            return [$ids->getAttributeValue($this->relatedKey) => []];
        }

        if (!is_array($ids)) {
            return [$ids => []];
        }

        $result = [];
        foreach ($ids as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $value;
            } elseif ($value instanceof Model) {
                $result[$value->getAttributeValue($this->relatedKey)] = [];
            } else {
                $result[$value] = [];
            }
        }

        return $result;
    }

    private function parentKeyValue(): mixed
    {
        $value = $this->parent->getAttributeValue($this->parentKey);
        if ($value === null) {
            throw new DatabaseException('Save the parent model before changing a many-to-many relation.');
        }
        return $value;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getForeignPivotKeyName(): string
    {
        return $this->foreignPivotKey;
    }

    public function getRelatedPivotKeyName(): string
    {
        return $this->relatedPivotKey;
    }
}
