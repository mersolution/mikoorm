<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\ORM\Traits;

use Miko\Database\ORM\Events\ModelEvent;
use Miko\Database\ORM\QueryBuilder;

/**
 * SoftDeletes trait - delete() sets DeletedAt instead of removing the row.
 *
 * Override the column with: const DELETED_AT = 'RemovedAt';
 */
trait SoftDeletes
{
    protected bool $forceDeleting = false;

    protected static function bootSoftDeletes(): void
    {
        static::addGlobalScope('soft_deletes', function (QueryBuilder $query) {
            $query->whereNull($query->getModel()->getQualifiedDeletedAtColumn());
        });
    }

    public static function getDeletedAtColumn(): string
    {
        return defined(static::class . '::DELETED_AT') ? constant(static::class . '::DELETED_AT') : 'DeletedAt';
    }

    public function getQualifiedDeletedAtColumn(): string
    {
        return static::getTable() . '.' . static::getDeletedAtColumn();
    }

    protected function performDeleteOnModel(): void
    {
        if ($this->forceDeleting) {
            parent::performDeleteOnModel();
            return;
        }

        $this->runSoftDelete();
    }

    protected function runSoftDelete(): void
    {
        $time = $this->freshTimestampString();
        $columns = [static::getDeletedAtColumn() => $time];

        if ($this->usesTimestamps()) {
            $columns[$this->getUpdatedAtColumn()] = $time;
        }

        $this->writeColumns($columns);
    }

    /**
     * Physically delete the row
     */
    public function forceDelete(): bool
    {
        if ($this->fireModelEvent(ModelEvent::FORCE_DELETING) === false) {
            return false;
        }

        $this->forceDeleting = true;
        try {
            $deleted = $this->delete();
        } finally {
            $this->forceDeleting = false;
        }

        if ($deleted) {
            $this->fireModelEvent(ModelEvent::FORCE_DELETED);
        }

        return $deleted;
    }

    /**
     * Restore a soft-deleted model
     */
    public function restore(): bool
    {
        if (!$this->exists) {
            return false;
        }

        if ($this->fireModelEvent(ModelEvent::RESTORING) === false) {
            return false;
        }

        $columns = [static::getDeletedAtColumn() => null];
        if ($this->usesTimestamps()) {
            $columns[$this->getUpdatedAtColumn()] = $this->freshTimestampString();
        }

        $this->writeColumns($columns);

        $this->fireModelEvent(ModelEvent::RESTORED);

        return true;
    }

    public function trashed(): bool
    {
        return $this->getAttributeValue(static::getDeletedAtColumn()) !== null;
    }

    public function isForceDeleting(): bool
    {
        return $this->forceDeleting;
    }

    public static function withTrashed(): QueryBuilder
    {
        return static::query()->withTrashed();
    }

    public static function onlyTrashed(): QueryBuilder
    {
        return static::query()->onlyTrashed();
    }

    public static function withoutTrashed(): QueryBuilder
    {
        return static::query();
    }

    private function writeColumns(array $columns): void
    {
        $connection = $this->getConnection();
        $grammar = $connection->getGrammar();
        $sets = [];
        $values = [];

        foreach ($columns as $column => $value) {
            $sets[] = $grammar->wrap($column) . ' = ?';
            $values[] = $value;
            $this->attributes[$column] = $value;
            $this->original[$column] = $value;
        }

        $values[] = $this->getOriginalKey();

        $connection->execute(
            'UPDATE ' . $grammar->wrapTable(static::getTable()) . ' SET ' . implode(', ', $sets)
            . ' WHERE ' . $grammar->wrap($this->primaryKey) . ' = ?',
            $values
        );
    }
}
