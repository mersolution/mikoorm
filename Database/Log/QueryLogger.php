<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\Log;

/**
 * Query Logger - Logs all database queries with timing
 */
class QueryLogger
{
    private static array $queries = [];
    private static bool $enabled = false;
    private static ?string $logFile = null;
    private static float $slowThreshold = 1000; // ms

    /**
     * Enable query logging
     */
    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Disable query logging
     */
    public static function disable(): void
    {
        self::$enabled = false;
    }

    /**
     * Set log file path
     */
    public static function setLogFile(string $path): void
    {
        self::$logFile = $path;
    }

    /**
     * Set slow query threshold in milliseconds
     */
    public static function setSlowThreshold(float $ms): void
    {
        self::$slowThreshold = $ms;
    }

    /**
     * Log a query
     */
    public static function log(string $sql, array $bindings = [], float $timeMs = 0): void
    {
        if (!self::$enabled) {
            return;
        }

        $entry = [
            'sql' => $sql,
            'bindings' => $bindings,
            'time_ms' => round($timeMs, 2),
            'timestamp' => date('Y-m-d H:i:s.u'),
            'memory' => memory_get_usage(true),
            'is_slow' => $timeMs >= self::$slowThreshold
        ];

        self::$queries[] = $entry;

        if (self::$logFile && $entry['is_slow']) {
            self::writeToFile($entry);
        }
    }

    /**
     * Get all logged queries
     */
    public static function getQueries(): array
    {
        return self::$queries;
    }

    /**
     * Get slow queries only
     */
    public static function getSlowQueries(): array
    {
        return array_filter(self::$queries, fn($q) => $q['is_slow']);
    }

    /**
     * Get query count
     */
    public static function getQueryCount(): int
    {
        return count(self::$queries);
    }

    /**
     * Get total query time
     */
    public static function getTotalTime(): float
    {
        return array_sum(array_column(self::$queries, 'time_ms'));
    }

    /**
     * Get average query time
     */
    public static function getAverageTime(): float
    {
        $count = count(self::$queries);
        return $count > 0 ? self::getTotalTime() / $count : 0;
    }

    /**
     * Get statistics
     */
    public static function getStats(): array
    {
        return [
            'total_queries' => self::getQueryCount(),
            'total_time_ms' => round(self::getTotalTime(), 2),
            'average_time_ms' => round(self::getAverageTime(), 2),
            'slow_queries' => count(self::getSlowQueries()),
            'memory_peak' => memory_get_peak_usage(true)
        ];
    }

    /**
     * Clear logged queries
     */
    public static function clear(): void
    {
        self::$queries = [];
    }

    /**
     * Write slow query to file
     */
    private static function writeToFile(array $entry): void
    {
        // Check file size and rotate if exceeds limit from env
        $maxSizeMB = (int) ($_ENV['LOG_MAX_SIZE_MB'] ?? $_SERVER['LOG_MAX_SIZE_MB'] ?? getenv('LOG_MAX_SIZE_MB') ?: 3);
        if (file_exists(self::$logFile)) {
            $fileSizeMB = filesize(self::$logFile) / 1024 / 1024;
            if ($fileSizeMB > $maxSizeMB) {
                unlink(self::$logFile);
            }
        }

        $line = sprintf(
            "[%s] SLOW QUERY (%.2fms): %s | Bindings: %s\n",
            $entry['timestamp'],
            $entry['time_ms'],
            $entry['sql'],
            json_encode($entry['bindings'])
        );

        file_put_contents(self::$logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Format query with bindings for debugging
     */
    public static function formatQuery(string $sql, array $bindings): string
    {
        $formatted = $sql;
        foreach ($bindings as $binding) {
            $value = is_string($binding) ? "'{$binding}'" : (string)$binding;
            $formatted = preg_replace('/\?/', $value, $formatted, 1);
        }
        return $formatted;
    }
}
