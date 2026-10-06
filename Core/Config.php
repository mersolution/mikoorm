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
    private static array $loadedPaths = [];

    /**
     * Environment variables loaded from the .env file
     */
    private static ?array $envCache = null;

    /**
     * Explicit .env file (Config::setEnvFile() or MIKO_ENV_FILE)
     */
    private static ?string $envFile = null;

    /**
     * Load all *.php files of a config folder (file name = key)
     */
    public static function load(string $configPath): void
    {
        $real = realpath($configPath) ?: $configPath;
        if (isset(self::$loadedPaths[$real])) {
            return;
        }

        foreach (glob(rtrim($configPath, '/\\') . '/*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            self::$items[$key] = require $file;
            $lower = strtolower($key);
            if ($lower !== $key && !isset(self::$items[$lower])) {
                self::$items[$lower] = &self::$items[$key];
            }
        }

        self::$loadedPaths[$real] = true;
    }

    /**
     * Get configuration value (dot notation, e.g. 'database.default')
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value ?? $default;
    }

    /**
     * Set configuration value
     */
    public static function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $config = &self::$items;

        while (count($keys) > 1) {
            $segment = array_shift($keys);

            if (!isset($config[$segment]) || !is_array($config[$segment])) {
                $config[$segment] = [];
            }

            $config = &$config[$segment];
        }

        $config[array_shift($keys)] = $value;
    }

    public static function has(string $key): bool
    {
        $value = self::$items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }

        return true;
    }

    public static function all(): array
    {
        return self::$items;
    }

    /**
     * Forget loaded config files and env cache
     */
    public static function reset(): void
    {
        self::$items = [];
        self::$loadedPaths = [];
        self::$envCache = null;
    }

    // ========================================
    // Environment
    // ========================================

    /**
     * Use a specific .env file
     */
    public static function setEnvFile(?string $path): void
    {
        self::$envFile = $path;
        self::$envCache = null;
    }

    /**
     * Get an environment variable.
     * Real environment variables (Docker, SetEnv, getenv) win over the .env file.
     */
    public static function env(string $key, mixed $default = null): mixed
    {
        $real = self::realEnv($key);
        if ($real !== null) {
            return self::castValue($real);
        }

        if (self::$envCache === null) {
            self::loadEnv();
        }

        return array_key_exists($key, self::$envCache) && self::$envCache[$key] !== null
            ? self::$envCache[$key]
            : $default;
    }

    /**
     * .env file candidates, first existing one is used:
     *   MIKO_ENV_FILE / Config::setEnvFile(), <project>/.env, <project>/Env/Config.env, <library>/.env
     * (<project> is the folder that contains the library folder)
     */
    public static function envCandidates(): array
    {
        $library = dirname(__DIR__);
        $project = dirname($library);

        $candidates = [];
        $explicit = self::$envFile ?? self::realEnv('MIKO_ENV_FILE');
        if (is_string($explicit) && $explicit !== '') {
            $candidates[] = $explicit;
        }

        $candidates[] = $project . DIRECTORY_SEPARATOR . '.env';
        $candidates[] = $project . DIRECTORY_SEPARATOR . 'Env' . DIRECTORY_SEPARATOR . 'Config.env';
        $candidates[] = $library . DIRECTORY_SEPARATOR . '.env';

        return $candidates;
    }

    private static function realEnv(string $key): ?string
    {
        if (isset($_ENV[$key]) && is_string($_ENV[$key])) {
            return $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && !str_starts_with($key, 'HTTP_')) {
            return $_SERVER[$key];
        }
        $value = getenv($key);
        return $value === false ? null : $value;
    }

    private static function loadEnv(): void
    {
        self::$envCache = [];

        $envFile = null;
        foreach (self::envCandidates() as $path) {
            if (is_file($path)) {
                $envFile = $path;
                break;
            }
        }

        if ($envFile === null) {
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $name = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
                $value = substr($value, 1, -1);
            } elseif (($hash = strpos($value, ' #')) !== false) {
                // Inline comment on an unquoted value
                $value = rtrim(substr($value, 0, $hash));
            }

            self::$envCache[$name] = self::castValue($value);
        }
    }

    private static function castValue(string $value): mixed
    {
        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
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
 */
function env(string $key, mixed $default = null): mixed
{
    return Config::env($key, $default);
}
