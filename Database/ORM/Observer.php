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
 * Base Observer class
 * 
 * Usage:
 * class UserObserver extends Observer {
 *     public function creating(Model $model): void { }
 *     public function created(Model $model): void { }
 *     public function updating(Model $model): void { }
 *     public function updated(Model $model): void { }
 *     public function deleting(Model $model): void { }
 *     public function deleted(Model $model): void { }
 *     public function saving(Model $model): void { }
 *     public function saved(Model $model): void { }
 * }
 * 
 * // Register observer
 * User::observe(UserObserver::class);
 */
abstract class Observer
{
    /**
     * Called before creating a new model
     */
    public function creating(Model $model): bool
    {
        return true;
    }

    /**
     * Called after creating a new model
     */
    public function created(Model $model): void
    {
    }

    /**
     * Called before updating a model
     */
    public function updating(Model $model): bool
    {
        return true;
    }

    /**
     * Called after updating a model
     */
    public function updated(Model $model): void
    {
    }

    /**
     * Called before deleting a model
     */
    public function deleting(Model $model): bool
    {
        return true;
    }

    /**
     * Called after deleting a model
     */
    public function deleted(Model $model): void
    {
    }

    /**
     * Called before saving (create or update)
     */
    public function saving(Model $model): bool
    {
        return true;
    }

    /**
     * Called after saving (create or update)
     */
    public function saved(Model $model): void
    {
    }

    /**
     * Called when model is retrieved from database
     */
    public function retrieved(Model $model): void
    {
    }

    /**
     * Called before restoring a soft-deleted model
     */
    public function restoring(Model $model): bool
    {
        return true;
    }

    /**
     * Called after restoring a soft-deleted model
     */
    public function restored(Model $model): void
    {
    }

    /**
     * Called before force deleting a model
     */
    public function forceDeleting(Model $model): bool
    {
        return true;
    }

    /**
     * Called after force deleting a model
     */
    public function forceDeleted(Model $model): void
    {
    }
}

/**
 * Observer Manager - Manages model observers
 */
class ObserverManager
{
    private static array $observers = [];

    /**
     * Register an observer for a model
     */
    public static function register(string $modelClass, string|Observer $observer): void
    {
        if (is_string($observer)) {
            $observer = new $observer();
        }

        if (!isset(self::$observers[$modelClass])) {
            self::$observers[$modelClass] = [];
        }

        self::$observers[$modelClass][] = $observer;
    }

    /**
     * Get observers for a model
     */
    public static function getObservers(string $modelClass): array
    {
        return self::$observers[$modelClass] ?? [];
    }

    /**
     * Fire an event on all observers
     * 
     * @return bool False if any observer returns false (for "before" events)
     */
    public static function fire(string $modelClass, string $event, Model $model): bool
    {
        $observers = self::getObservers($modelClass);

        foreach ($observers as $observer) {
            if (method_exists($observer, $event)) {
                $result = $observer->$event($model);
                
                // For "before" events, if observer returns false, stop
                if ($result === false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Remove all observers for a model
     */
    public static function flush(string $modelClass): void
    {
        unset(self::$observers[$modelClass]);
    }

    /**
     * Remove all observers
     */
    public static function flushAll(): void
    {
        self::$observers = [];
    }
}
