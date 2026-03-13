<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\Cache;

/**
 * Query Cache - Caches query results for improved performance
 */
class QueryCache
{
    private static array $cache = [];
    private static bool $enabled = true;
    private static int $defaultTtl = 300; // 5 minutes
    private static int $maxSize = 1000;
    private static array $tags = [];

    /**
     * Enable caching
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Disable caching
     */
    public static function disable(): void
    {
        self::$enabled = false;
    }

    /**
     * Set default TTL in seconds
     */
    public static function setDefaultTtl(int $seconds): void
    {
        self::$defaultTtl = $seconds;
    }

    /**
     * Set max cache size
     */
    public static function setMaxSize(int $size): void
    {
        self::$maxSize = $size;
    }

    /**
     * Generate cache key from query and bindings
     */
    public static function generateKey(string $sql, array $bindings = []): string
    {
        return md5($sql . serialize($bindings));
    }

    /**
     * Get cached result
     */
    public static function get(string $key): mixed
    {
        if (!self::$enabled || !isset(self::$cache[$key])) {
            return null;
        }

        $entry = self::$cache[$key];

        if ($entry['expires_at'] < time()) {
            unset(self::$cache[$key]);
            return null;
        }

        $entry['hits']++;
        self::$cache[$key] = $entry;

        return $entry['data'];
    }

    /**
     * Store result in cache
     */
    public static function set(string $key, mixed $data, ?int $ttl = null, array $tags = []): void
    {
        if (!self::$enabled) {
            return;
        }

        if (count(self::$cache) >= self::$maxSize) {
            self::evict();
        }

        self::$cache[$key] = [
            'data' => $data,
            'expires_at' => time() + ($ttl ?? self::$defaultTtl),
            'created_at' => time(),
            'hits' => 0,
            'tags' => $tags
        ];

        foreach ($tags as $tag) {
            if (!isset(self::$tags[$tag])) {
                self::$tags[$tag] = [];
            }
            self::$tags[$tag][] = $key;
        }
    }

    /**
     * Remember - get from cache or execute callback
     */
    public static function remember(string $key, callable $callback, ?int $ttl = null, array $tags = []): mixed
    {
        $cached = self::get($key);

        if ($cached !== null) {
            return $cached;
        }

        $result = $callback();
        self::set($key, $result, $ttl, $tags);

        return $result;
    }

    /**
     * Invalidate by key
     */
    public static function forget(string $key): void
    {
        unset(self::$cache[$key]);
    }

    /**
     * Invalidate by tag
     */
    public static function forgetByTag(string $tag): int
    {
        if (!isset(self::$tags[$tag])) {
            return 0;
        }

        $count = 0;
        foreach (self::$tags[$tag] as $key) {
            if (isset(self::$cache[$key])) {
                unset(self::$cache[$key]);
                $count++;
            }
        }

        unset(self::$tags[$tag]);
        return $count;
    }

    /**
     * Invalidate by table name (convenience method)
     */
    public static function forgetTable(string $table): int
    {
        return self::forgetByTag("table:{$table}");
    }

    /**
     * Clear all cache
     */
    public static function flush(): void
    {
        self::$cache = [];
        self::$tags = [];
    }

    /**
     * Get cache statistics
     */
    public static function getStats(): array
    {
        $totalHits = 0;
        $expired = 0;
        $now = time();

        foreach (self::$cache as $entry) {
            $totalHits += $entry['hits'];
            if ($entry['expires_at'] < $now) {
                $expired++;
            }
        }

        return [
            'entries' => count(self::$cache),
            'max_size' => self::$maxSize,
            'total_hits' => $totalHits,
            'expired' => $expired,
            'tags' => count(self::$tags),
            'enabled' => self::$enabled
        ];
    }

    /**
     * Evict oldest/least used entries
     */
    private static function evict(): void
    {
        uasort(self::$cache, function ($a, $b) {
            if ($a['hits'] === $b['hits']) {
                return $a['created_at'] <=> $b['created_at'];
            }
            return $a['hits'] <=> $b['hits'];
        });

        $toRemove = (int)(self::$maxSize * 0.2);
        $keys = array_slice(array_keys(self::$cache), 0, $toRemove);

        foreach ($keys as $key) {
            unset(self::$cache[$key]);
        }
    }
}
