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
 * Fluent Query Builder - QueryBuilder plus UNION support.
 *
 * Every builder uses its own parameter prefix, so the bindings of united
 * queries never collide.
 */
class FluentQueryBuilder extends QueryBuilder
{
    protected array $unions = [];

    /**
     * whereBetween('Price', 10, 20) or whereBetween('Price', [10, 20])
     */
    public function whereBetween(string $column, mixed $min, mixed $max = null): self
    {
        return parent::whereBetween($column, is_array($min) ? $min : [$min, $max]);
    }

    public function union(QueryBuilder $query, bool $all = false): self
    {
        $this->unions[] = ['query' => $query, 'all' => $all];
        return $this;
    }

    public function unionAll(QueryBuilder $query): self
    {
        return $this->union($query, true);
    }

    public function toSql(): string
    {
        if ($this->unions === []) {
            return parent::toSql();
        }

        $base = clone $this;
        $base->unions = [];
        $base->orderBy = [];
        $base->limit = null;
        $base->offset = null;
        $sql = $base->toSql();

        foreach ($this->unions as $i => $union) {
            $query = $union['query'];
            $subSql = $query->toSql();
            if ($query->orderBy !== [] || $query->limit !== null || $query->offset !== null) {
                $subSql = "SELECT * FROM ({$subSql}) AS miko_union_{$i}";
            }
            $sql .= ($union['all'] ? ' UNION ALL ' : ' UNION ') . $subSql;
        }

        if ($this->orderBy !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }

        return $sql . $this->grammar()->compileLimit($this->limit, $this->offset, $this->orderBy !== []);
    }

    /**
     * Bindings of this query and all united queries
     */
    public function getBindings(): array
    {
        $bindings = $this->bindings;
        foreach ($this->unions as $union) {
            $bindings = array_merge($bindings, $union['query']->getBindings());
        }
        return $bindings;
    }

    /**
     * COUNT over the whole union (get/count/exists and their *Async() versions use these statements)
     */
    protected function countStatement(): array
    {
        if ($this->unions === []) {
            return parent::countStatement();
        }

        $query = clone $this;
        $query->orderBy = [];
        $query->limit = null;
        $query->offset = null;

        return ['SELECT COUNT(*) AS aggregate FROM (' . $query->toSql() . ') AS miko_count', $query->getBindings()];
    }

    protected function existsStatement(): array
    {
        if ($this->unions === []) {
            return parent::existsStatement();
        }

        $query = clone $this;
        $query->orderBy = [];
        $query->limit = null;
        $query->offset = null;

        return [
            'SELECT 1 AS miko_exists FROM (' . $query->toSql() . ') AS miko_exists_query' . $this->grammar()->compileLimit(1, null, false),
            $query->getBindings(),
        ];
    }
}
