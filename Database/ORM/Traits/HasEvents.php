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
 * HasEvents trait - Adds event support to models
 */
trait HasEvents
{
    /**
     * Boot the trait
     */
    protected static function bootHasEvents(): void
    {
        // This will be called when the model is booted
    }

    /**
     * Fire a model event
     */
    protected function fireModelEvent(string $event): bool
    {
        return ModelEvent::fire($event, $this);
    }

    /**
     * Register a creating event listener
     */
    public static function creating(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::CREATING, static::class, $callback);
    }

    /**
     * Register a created event listener
     */
    public static function created(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::CREATED, static::class, $callback);
    }

    /**
     * Register an updating event listener
     */
    public static function updating(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::UPDATING, static::class, $callback);
    }

    /**
     * Register an updated event listener
     */
    public static function updated(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::UPDATED, static::class, $callback);
    }

    /**
     * Register a saving event listener
     */
    public static function saving(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::SAVING, static::class, $callback);
    }

    /**
     * Register a saved event listener
     */
    public static function saved(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::SAVED, static::class, $callback);
    }

    /**
     * Register a deleting event listener
     */
    public static function deleting(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::DELETING, static::class, $callback);
    }

    /**
     * Register a deleted event listener
     */
    public static function deleted(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::DELETED, static::class, $callback);
    }

    /**
     * Register a restoring event listener
     */
    public static function restoring(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::RESTORING, static::class, $callback);
    }

    /**
     * Register a restored event listener
     */
    public static function restored(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::RESTORED, static::class, $callback);
    }
}
