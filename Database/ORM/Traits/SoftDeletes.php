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

/**
 * SoftDeletes trait - Adds soft delete functionality to models
 */
trait SoftDeletes
{
    /**
     * Indicates if the model is currently force deleting
     */
    protected bool $forceDeleting = false;

    /**
     * Boot the soft deleting trait
     */
    protected static function bootSoftDeletes(): void
    {
        // Add global scope to exclude soft deleted records
        static::addGlobalScope('soft_deletes', function($query) {
            $query->whereNull(static::getDeletedAtColumn());
        });
    }

    /**
     * Get the name of the "deleted at" column
     */
    public static function getDeletedAtColumn(): string
    {
        return 'deleted_at';
    }

    /**
     * Perform the actual delete query
     */
    public function delete(): bool
    {
        if ($this->forceDeleting) {
            return $this->performDeleteOnModel();
        }

        return $this->runSoftDelete();
    }

    /**
     * Force a hard delete on a soft deleted model
     */
    public function forceDelete(): bool
    {
        $this->forceDeleting = true;
        
        $deleted = $this->delete();
        
        $this->forceDeleting = false;
        
        return $deleted;
    }

    /**
     * Perform the actual delete query on model
     */
    protected function performDeleteOnModel(): bool
    {
        if (!$this->exists) {
            return false;
        }

        // Fire deleting event
        if ($this->fireModelEvent(ModelEvent::DELETING) === false) {
            return false;
        }

        $sql = sprintf(
            "DELETE FROM %s WHERE %s = ?",
            static::$table,
            $this->primaryKey
        );

        $this->getConnection()->execute($sql, [$this->attributes[$this->primaryKey]]);
        $this->exists = false;

        // Fire deleted event
        $this->fireModelEvent(ModelEvent::DELETED);

        return true;
    }

    /**
     * Perform the soft delete
     */
    protected function runSoftDelete(): bool
    {
        // Fire deleting event
        if ($this->fireModelEvent(ModelEvent::DELETING) === false) {
            return false;
        }

        $column = static::getDeletedAtColumn();
        $this->attributes[$column] = date('Y-m-d H:i:s');

        $sql = sprintf(
            "UPDATE %s SET %s = ? WHERE %s = ?",
            static::$table,
            $column,
            $this->primaryKey
        );

        $this->getConnection()->execute($sql, [
            $this->attributes[$column],
            $this->attributes[$this->primaryKey]
        ]);

        // Fire deleted event
        $this->fireModelEvent(ModelEvent::DELETED);

        return true;
    }

    /**
     * Restore a soft-deleted model
     */
    public function restore(): bool
    {
        // Fire restoring event
        if ($this->fireModelEvent(ModelEvent::RESTORING) === false) {
            return false;
        }

        $column = static::getDeletedAtColumn();
        $this->attributes[$column] = null;

        $sql = sprintf(
            "UPDATE %s SET %s = NULL WHERE %s = ?",
            static::$table,
            $column,
            $this->primaryKey
        );

        $this->getConnection()->execute($sql, [$this->attributes[$this->primaryKey]]);

        // Fire restored event
        $this->fireModelEvent(ModelEvent::RESTORED);

        return true;
    }

    /**
     * Determine if the model instance has been soft-deleted
     */
    public function trashed(): bool
    {
        return !is_null($this->attributes[static::getDeletedAtColumn()] ?? null);
    }

    /**
     * Query only trashed models
     */
    public static function onlyTrashed()
    {
        return static::query()->withoutGlobalScope('soft_deletes')
            ->whereNotNull(static::getDeletedAtColumn());
    }

    /**
     * Query with trashed models
     */
    public static function withTrashed()
    {
        return static::query()->withoutGlobalScope('soft_deletes');
    }

    /**
     * Query without trashed models (default behavior)
     */
    public static function withoutTrashed()
    {
        return static::query();
    }
}
