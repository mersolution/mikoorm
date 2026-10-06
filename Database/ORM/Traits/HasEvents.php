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
 * HasEvents trait - model lifecycle events.
 *
 * Returning false from a "before" listener (creating, updating, saving,
 * deleting, restoring, forceDeleting) cancels the operation.
 */
trait HasEvents
{
    protected static function bootHasEvents(): void
    {
    }

    protected function fireModelEvent(string $event): bool
    {
        return ModelEvent::fire($event, $this);
    }

    public static function retrieved(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::RETRIEVED, static::class, $callback);
    }

    public static function creating(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::CREATING, static::class, $callback);
    }

    public static function created(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::CREATED, static::class, $callback);
    }

    public static function updating(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::UPDATING, static::class, $callback);
    }

    public static function updated(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::UPDATED, static::class, $callback);
    }

    public static function saving(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::SAVING, static::class, $callback);
    }

    public static function saved(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::SAVED, static::class, $callback);
    }

    public static function deleting(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::DELETING, static::class, $callback);
    }

    public static function deleted(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::DELETED, static::class, $callback);
    }

    public static function restoring(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::RESTORING, static::class, $callback);
    }

    public static function restored(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::RESTORED, static::class, $callback);
    }

    public static function forceDeleting(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::FORCE_DELETING, static::class, $callback);
    }

    public static function forceDeleted(callable $callback): void
    {
        ModelEvent::listen(ModelEvent::FORCE_DELETED, static::class, $callback);
    }
}
