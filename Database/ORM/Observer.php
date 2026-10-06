<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 *
 * Observer - Model observer pattern for watching model events
 * Similar to mersolutionCore MersoObserver.cs
 */

namespace Miko\Database\ORM;

/**
 * Base Observer class - override only the events you need.
 *
 * class UserObserver extends Observer {
 *     public function creating(Model $model) { ... }   // return false to cancel
 *     public function created(Model $model): void { ... }
 * }
 *
 * User::observe(UserObserver::class);
 */
abstract class Observer
{
    public function retrieved(Model $model)
    {
    }

    public function creating(Model $model)
    {
        return true;
    }

    public function created(Model $model)
    {
    }

    public function updating(Model $model)
    {
        return true;
    }

    public function updated(Model $model)
    {
    }

    public function saving(Model $model)
    {
        return true;
    }

    public function saved(Model $model)
    {
    }

    public function deleting(Model $model)
    {
        return true;
    }

    public function deleted(Model $model)
    {
    }

    public function restoring(Model $model)
    {
        return true;
    }

    public function restored(Model $model)
    {
    }

    public function forceDeleting(Model $model)
    {
        return true;
    }

    public function forceDeleted(Model $model)
    {
    }
}

/**
 * Observer Manager - Manages model observers
 */
class ObserverManager
{
    private static array $observers = [];

    public static function register(string $modelClass, string|Observer $observer): void
    {
        self::$observers[$modelClass][] = is_string($observer) ? new $observer() : $observer;
    }

    public static function getObservers(string $modelClass): array
    {
        return self::$observers[$modelClass] ?? [];
    }

    public static function hasObservers(string $modelClass): bool
    {
        return !empty(self::$observers[$modelClass]);
    }

    /**
     * @return bool False if any observer returns false (for "before" events)
     */
    public static function fire(string $modelClass, string $event, Model $model): bool
    {
        foreach (self::$observers[$modelClass] ?? [] as $observer) {
            if (method_exists($observer, $event) && $observer->$event($model) === false) {
                return false;
            }
        }

        return true;
    }

    public static function flush(string $modelClass): void
    {
        unset(self::$observers[$modelClass]);
    }

    public static function flushAll(): void
    {
        self::$observers = [];
    }
}
