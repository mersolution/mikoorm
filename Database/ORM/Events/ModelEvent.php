<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\ORM\Events;

use Miko\Database\ORM\Model;

/**
 * Model Event class
 */
class ModelEvent
{
    public const CREATING = 'creating';
    public const CREATED = 'created';
    public const UPDATING = 'updating';
    public const UPDATED = 'updated';
    public const SAVING = 'saving';
    public const SAVED = 'saved';
    public const DELETING = 'deleting';
    public const DELETED = 'deleted';
    public const RESTORING = 'restoring';
    public const RESTORED = 'restored';

    private static array $listeners = [];

    /**
     * Register an event listener
     */
    public static function listen(string $event, string $modelClass, callable $callback): void
    {
        if (!isset(self::$listeners[$modelClass])) {
            self::$listeners[$modelClass] = [];
        }

        if (!isset(self::$listeners[$modelClass][$event])) {
            self::$listeners[$modelClass][$event] = [];
        }

        self::$listeners[$modelClass][$event][] = $callback;
    }

    /**
     * Fire an event
     */
    public static function fire(string $event, Model $model): bool
    {
        $modelClass = get_class($model);

        if (!isset(self::$listeners[$modelClass][$event])) {
            return true;
        }

        foreach (self::$listeners[$modelClass][$event] as $callback) {
            $result = $callback($model);
            
            // If callback returns false, stop event propagation
            if ($result === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Clear all listeners
     */
    public static function clearListeners(?string $modelClass = null): void
    {
        if ($modelClass === null) {
            self::$listeners = [];
        } else {
            unset(self::$listeners[$modelClass]);
        }
    }

    /**
     * Get all listeners for a model
     */
    public static function getListeners(string $modelClass): array
    {
        return self::$listeners[$modelClass] ?? [];
    }
}
