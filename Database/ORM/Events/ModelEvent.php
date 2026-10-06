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
use Miko\Database\ORM\ObserverManager;

/**
 * Model Event class
 */
class ModelEvent
{
    public const RETRIEVED = 'retrieved';
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
    public const FORCE_DELETING = 'forceDeleting';
    public const FORCE_DELETED = 'forceDeleted';

    private static array $listeners = [];

    public static function listen(string $event, string $modelClass, callable $callback): void
    {
        self::$listeners[$modelClass][$event][] = $callback;
    }

    /**
     * Fire an event; false when a listener or observer cancels it
     */
    public static function fire(string $event, Model $model): bool
    {
        $modelClass = get_class($model);

        if (ObserverManager::hasObservers($modelClass) && ObserverManager::fire($modelClass, $event, $model) === false) {
            return false;
        }

        foreach (self::$listeners[$modelClass][$event] ?? [] as $callback) {
            if ($callback($model) === false) {
                return false;
            }
        }

        return true;
    }

    public static function clearListeners(?string $modelClass = null): void
    {
        if ($modelClass === null) {
            self::$listeners = [];
        } else {
            unset(self::$listeners[$modelClass]);
        }
    }

    public static function getListeners(string $modelClass): array
    {
        return self::$listeners[$modelClass] ?? [];
    }
}
