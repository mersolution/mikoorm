<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Cache;

/**
 * APCu Cache - Persistent shared memory cache
 * 
 * Usage:
 * ApcuCache::put('key', $value, 300);     // Store for 5 minutes
 * $value = ApcuCache::get('key');          // Retrieve
 * ApcuCache::forget('key');                // Delete
 * ApcuCache::remember('key', 300, fn() => expensiveQuery());  // Get or compute
 */
class ApcuCache
{
    /**
     * Check if key exists in cache
     * 
     * @param string $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        return apcu_exists($key);
    }

    /**
     * Get value from cache
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $data = apcu_fetch($key, $success);
        
        if (!$success) {
            return $default;
        }
        
        return json_decode($data, true);
    }

    /**
     * Store value in cache
     * 
     * @param string $key
     * @param mixed $value
     * @param int $ttl Time to live in seconds (default: 60)
     * @return bool
     */
    public static function put(string $key, mixed $value, int $ttl = 60): bool
    {
        return apcu_store($key, json_encode($value, JSON_UNESCAPED_UNICODE), $ttl);
    }

    /**
     * Delete key from cache
     * 
     * @param string $key
     * @return bool
     */
    public static function forget(string $key): bool
    {
        return apcu_delete($key);
    }

    /**
     * Delete all keys with given prefix
     * 
     * @param string $prefix
     * @return int Number of deleted keys
     */
    public static function forgetPrefix(string $prefix): int
    {
        $count = 0;
        $iterator = new \APCUIterator('/^' . preg_quote($prefix, '/') . '/');

        foreach ($iterator as $item) {
            if (apcu_delete($item['key'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get value from cache or execute callback and store result
     * Uses lock mechanism to prevent cache stampede
     * 
     * @param string $key
     * @param int $ttl
     * @param callable $callback
     * @return mixed
     */
    public static function remember(string $key, int $ttl, callable $callback): mixed
    {
        // Try to get from cache first
        if (apcu_exists($key)) {
            $json = apcu_fetch($key, $success);
            
            if ($success && $json !== false) {
                return json_decode($json, true);
            }
        }

        // Lock mechanism to prevent cache stampede
        $lockKey = $key . '_lock';

        if (!apcu_add($lockKey, 1, 5)) {
            // Another process is computing, wait and try again
            usleep(200000); // 200ms

            $json = apcu_fetch($key, $success);
            
            if ($success && $json !== false) {
                return json_decode($json, true);
            }
        }

        // Compute value
        $value = $callback();

        // Store in cache
        apcu_store($key, json_encode($value, JSON_UNESCAPED_UNICODE), $ttl);
        apcu_delete($lockKey);

        return $value;
    }

    /**
     * Smart remember - skip cache if params are not empty
     * Useful for filtered queries where caching doesn't make sense
     * 
     * @param string $key
     * @param int $ttl
     * @param array $params If any param is truthy, skip cache
     * @param callable $callback
     * @return mixed
     */
    public static function rememberSmart(string $key, int $ttl, array $params, callable $callback): mixed
    {
        // If any filter param is set, skip cache
        if (!empty(array_filter($params))) {
            return $callback();
        }

        return self::remember($key, $ttl, $callback);
    }

    /**
     * Increment a numeric value
     * 
     * @param string $key
     * @param int $step
     * @return int|false
     */
    public static function increment(string $key, int $step = 1): int|false
    {
        return apcu_inc($key, $step);
    }

    /**
     * Decrement a numeric value
     * 
     * @param string $key
     * @param int $step
     * @return int|false
     */
    public static function decrement(string $key, int $step = 1): int|false
    {
        return apcu_dec($key, $step);
    }

    /**
     * Clear all cache
     * 
     * @return bool
     */
    public static function flush(): bool
    {
        return apcu_clear_cache();
    }

    /**
     * Get cache statistics
     * 
     * @return array
     */
    public static function stats(): array
    {
        $info = apcu_cache_info();
        $sma = apcu_sma_info();

        return [
            'hits' => $info['num_hits'] ?? 0,
            'misses' => $info['num_misses'] ?? 0,
            'entries' => $info['num_entries'] ?? 0,
            'memory_size' => $info['mem_size'] ?? 0,
            'memory_available' => $sma['avail_mem'] ?? 0,
            'memory_total' => ($sma['num_seg'] ?? 1) * ($sma['seg_size'] ?? 0),
            'uptime' => time() - ($info['start_time'] ?? time())
        ];
    }

    // ========================================
    // Tag Support (like mersolutionCore)
    // ========================================

    private const TAG_PREFIX = '_tag:';

    /**
     * Store value with tags
     * 
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     * @param array $tags
     * @return bool
     */
    public static function putWithTags(string $key, mixed $value, int $ttl = 60, array $tags = []): bool
    {
        $result = self::put($key, $value, $ttl);

        if ($result && !empty($tags)) {
            foreach ($tags as $tag) {
                self::addKeyToTag($tag, $key);
            }
        }

        return $result;
    }

    /**
     * Add key to tag set
     */
    private static function addKeyToTag(string $tag, string $key): void
    {
        $tagKey = self::TAG_PREFIX . $tag;
        $keys = apcu_fetch($tagKey, $success);
        
        if (!$success || !is_array($keys)) {
            $keys = [];
        }

        if (!in_array($key, $keys)) {
            $keys[] = $key;
            apcu_store($tagKey, $keys, 0); // No expiration for tag sets
        }
    }

    /**
     * Delete all keys with given tag
     * 
     * @param string $tag
     * @return int Number of deleted keys
     */
    public static function forgetByTag(string $tag): int
    {
        $tagKey = self::TAG_PREFIX . $tag;
        $keys = apcu_fetch($tagKey, $success);

        if (!$success || !is_array($keys)) {
            return 0;
        }

        $count = 0;
        foreach ($keys as $key) {
            if (apcu_delete($key)) {
                $count++;
            }
        }

        apcu_delete($tagKey);
        return $count;
    }

    /**
     * Get all keys for a tag
     * 
     * @param string $tag
     * @return array
     */
    public static function getTagKeys(string $tag): array
    {
        $tagKey = self::TAG_PREFIX . $tag;
        $keys = apcu_fetch($tagKey, $success);

        return ($success && is_array($keys)) ? $keys : [];
    }

    /**
     * Remember with tags
     * 
     * @param string $key
     * @param int $ttl
     * @param callable $callback
     * @param array $tags
     * @return mixed
     */
    public static function rememberWithTags(string $key, int $ttl, callable $callback, array $tags = []): mixed
    {
        if (apcu_exists($key)) {
            $json = apcu_fetch($key, $success);
            
            if ($success && $json !== false) {
                return json_decode($json, true);
            }
        }

        $lockKey = $key . '_lock';

        if (!apcu_add($lockKey, 1, 5)) {
            usleep(200000);

            $json = apcu_fetch($key, $success);
            
            if ($success && $json !== false) {
                return json_decode($json, true);
            }
        }

        $value = $callback();

        self::putWithTags($key, $value, $ttl, $tags);
        apcu_delete($lockKey);

        return $value;
    }
}
