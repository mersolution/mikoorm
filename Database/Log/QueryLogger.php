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

use Miko\Log\Logger;

/**
 * Query Logger - every query run through Connection::execute() is timed here.
 *
 * Enabled by Config/Database.php "query_log" (or QueryLogger::enable()).
 * Keeps the last N queries in memory and writes slow ones to the log file.
 */
class QueryLogger
{
    private static array $queries = [];
    private static bool $enabled = false;
    private static ?string $logFile = null;
    private static float $slowThreshold = 1000; // ms
    private static int $maxEntries = 1000;
    private static int $totalQueries = 0;
    private static float $totalTime = 0.0;
    private static int $slowCount = 0;

    public static function enable(): void
    {
        self::$enabled = true;
    }

    public static function disable(): void
    {
        self::$enabled = false;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    public static function setLogFile(?string $path): void
    {
        self::$logFile = $path;
    }

    public static function setSlowThreshold(float $ms): void
    {
        self::$slowThreshold = $ms;
    }

    /**
     * Number of queries kept in memory (oldest dropped first)
     */
    public static function setMaxEntries(int $entries): void
    {
        self::$maxEntries = max(1, $entries);
    }

    /**
     * @param bool $async query ran on a parallel (async) connection
     */
    public static function log(string $sql, array $bindings = [], float $timeMs = 0, bool $async = false): void
    {
        if (!self::$enabled) {
            return;
        }

        $isSlow = $timeMs >= self::$slowThreshold;
        self::$totalQueries++;
        self::$totalTime += $timeMs;
        if ($isSlow) {
            self::$slowCount++;
        }

        self::$queries[] = [
            'sql' => $sql,
            'bindings' => $bindings,
            'time_ms' => round($timeMs, 2),
            'timestamp' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            'is_slow' => $isSlow,
            'async' => $async,
        ];

        if (count(self::$queries) > self::$maxEntries) {
            array_shift(self::$queries);
        }

        if ($isSlow && self::$logFile) {
            self::writeToFile(end(self::$queries));
        }
    }

    public static function getQueries(): array
    {
        return self::$queries;
    }

    public static function getSlowQueries(): array
    {
        return array_values(array_filter(self::$queries, fn($q) => $q['is_slow']));
    }

    public static function getQueryCount(): int
    {
        return self::$totalQueries;
    }

    public static function getTotalTime(): float
    {
        return self::$totalTime;
    }

    public static function getAverageTime(): float
    {
        return self::$totalQueries > 0 ? self::$totalTime / self::$totalQueries : 0.0;
    }

    public static function getStats(): array
    {
        return [
            'total_queries' => self::$totalQueries,
            'total_time_ms' => round(self::$totalTime, 2),
            'average_time_ms' => round(self::getAverageTime(), 2),
            'slow_queries' => self::$slowCount,
            'memory_peak' => memory_get_peak_usage(true),
        ];
    }

    public static function clear(): void
    {
        self::$queries = [];
        self::$totalQueries = 0;
        self::$totalTime = 0.0;
        self::$slowCount = 0;
    }

    private static function writeToFile(array $entry): void
    {
        Logger::rotateIfLarge(self::$logFile);

        $line = sprintf(
            "[%s] SLOW QUERY%s (%.2fms): %s | Bindings: %s\n",
            $entry['timestamp'],
            !empty($entry['async']) ? ' [async]' : '',
            $entry['time_ms'],
            $entry['sql'],
            json_encode(Logger::maskBindings($entry['bindings']), JSON_UNESCAPED_UNICODE)
        );

        @file_put_contents(self::$logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Query with values inlined - for debugging output only, never execute it
     */
    public static function formatQuery(string $sql, array $bindings): string
    {
        $format = static function (mixed $value): string {
            return match (true) {
                $value === null => 'NULL',
                is_bool($value) => $value ? '1' : '0',
                is_int($value), is_float($value) => (string) $value,
                default => "'" . str_replace("'", "''", (string) $value) . "'",
            };
        };

        foreach ($bindings as $key => $value) {
            if (is_string($key)) {
                $name = $key[0] === ':' ? $key : ':' . $key;
                $sql = preg_replace('/' . preg_quote($name, '/') . '\b/', $format($value), $sql);
            } else {
                $sql = preg_replace('/\?/', $format($value), $sql, 1);
            }
        }

        return $sql;
    }
}
