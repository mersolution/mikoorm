<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Core;

/**
 * Config Class - Configuration management
 */
class Config
{
    private static array $items = [];
    private static bool $loaded = false;

    /**
     * Load configuration files
     */
    public static function load(string $configPath): void
    {
        if (self::$loaded) {
            return;
        }

        // Load all config files
        $files = glob($configPath . '/*.php');
        
        foreach ($files as $file) {
            $key = basename($file, '.php');
            self::$items[$key] = require $file;
        }

        self::$loaded = true;
    }

    /**
     * Get configuration value
     * 
     * @param string $key Dot notation key (e.g., 'database.default')
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = self::$items;

        foreach ($keys as $segment) {
            if (!isset($value[$segment])) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Set configuration value
     */
    public static function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $config = &self::$items;

        while (count($keys) > 1) {
            $key = array_shift($keys);
            
            if (!isset($config[$key]) || !is_array($config[$key])) {
                $config[$key] = [];
            }
            
            $config = &$config[$key];
        }

        $config[array_shift($keys)] = $value;
    }

    /**
     * Check if configuration key exists
     */
    public static function has(string $key): bool
    {
        $keys = explode('.', $key);
        $value = self::$items;

        foreach ($keys as $segment) {
            if (!isset($value[$segment])) {
                return false;
            }
            $value = $value[$segment];
        }

        return true;
    }

    /**
     * Get all configuration
     */
    public static function all(): array
    {
        return self::$items;
    }

    /**
     * Environment variables cache
     */
    private static ?array $envCache = null;

    /**
     * Get environment variable (class method - optimized)
     */
    public static function env(string $key, mixed $default = null): mixed
    {
        if (self::$envCache === null) {
            self::loadEnv();
        }
        return self::$envCache[$key] ?? $default;
    }

    /**
     * Load environment variables from .env file
     */
    private static function loadEnv(): void
    {
        self::$envCache = [];
        
        // Try multiple possible locations
        $possiblePaths = [
            __DIR__ . '/../../../Env/Config.env',
            __DIR__ . '/../../.env',
            __DIR__ . '/../../../.env',
        ];
        
        $envFile = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $envFile = $path;
                break;
            }
        }
        
        if ($envFile === null) {
            return;
        }
        
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Skip comments
            $line = trim($line);
            if (empty($line) || $line[0] === '#') {
                continue;
            }
            
            // Parse KEY=VALUE
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            
            $name = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            
            // Remove quotes
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }
            
            // Handle special values
            $value = match(strtolower($value)) {
                'true', '(true)' => true,
                'false', '(false)' => false,
                'null', '(null)' => null,
                'empty', '(empty)' => '',
                default => $value
            };
            
            self::$envCache[$name] = $value;
        }
    }

    /**
     * Clear environment cache (useful for testing)
     */
    public static function clearEnvCache(): void
    {
        self::$envCache = null;
    }
}

/**
 * Global helper function to get environment variable
 * Delegates to Config::env() for optimized caching
 */
function env(string $key, mixed $default = null): mixed
{
    return Config::env($key, $default);
}
