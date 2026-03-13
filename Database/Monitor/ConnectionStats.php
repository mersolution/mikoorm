<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\Monitor;

/**
 * Connection Statistics Monitor
 * 
 * Tracks database connection metrics and performance statistics
 */
class ConnectionStats
{
    private static array $stats = [
        'total_connections' => 0,
        'active_connections' => 0,
        'failed_connections' => 0,
        'total_queries' => 0,
        'slow_queries' => 0,
        'total_query_time' => 0.0,
        'peak_connections' => 0,
        'last_connection_time' => null,
        'last_query_time' => null,
    ];

    private static float $slowQueryThreshold = 1.0; // seconds

    /**
     * Record a new connection
     */
    public static function recordConnection(): void
    {
        self::$stats['total_connections']++;
        self::$stats['active_connections']++;
        self::$stats['last_connection_time'] = microtime(true);

        if (self::$stats['active_connections'] > self::$stats['peak_connections']) {
            self::$stats['peak_connections'] = self::$stats['active_connections'];
        }
    }

    /**
     * Record connection release
     */
    public static function recordRelease(): void
    {
        if (self::$stats['active_connections'] > 0) {
            self::$stats['active_connections']--;
        }
    }

    /**
     * Record a failed connection attempt
     */
    public static function recordFailedConnection(): void
    {
        self::$stats['failed_connections']++;
    }

    /**
     * Record a query execution
     */
    public static function recordQuery(float $executionTime): void
    {
        self::$stats['total_queries']++;
        self::$stats['total_query_time'] += $executionTime;
        self::$stats['last_query_time'] = microtime(true);

        if ($executionTime >= self::$slowQueryThreshold) {
            self::$stats['slow_queries']++;
        }
    }

    /**
     * Get all statistics
     */
    public static function getStats(): array
    {
        return array_merge(self::$stats, [
            'avg_query_time' => self::getAverageQueryTime(),
            'queries_per_second' => self::getQueriesPerSecond(),
        ]);
    }

    /**
     * Get specific statistic
     */
    public static function get(string $key): mixed
    {
        return self::$stats[$key] ?? null;
    }

    /**
     * Get average query time
     */
    public static function getAverageQueryTime(): float
    {
        if (self::$stats['total_queries'] === 0) {
            return 0.0;
        }

        return self::$stats['total_query_time'] / self::$stats['total_queries'];
    }

    /**
     * Get queries per second (approximate)
     */
    public static function getQueriesPerSecond(): float
    {
        if (self::$stats['last_connection_time'] === null) {
            return 0.0;
        }

        $elapsed = microtime(true) - self::$stats['last_connection_time'];
        
        if ($elapsed <= 0) {
            return 0.0;
        }

        return self::$stats['total_queries'] / $elapsed;
    }

    /**
     * Set slow query threshold
     */
    public static function setSlowQueryThreshold(float $seconds): void
    {
        self::$slowQueryThreshold = $seconds;
    }

    /**
     * Get slow query threshold
     */
    public static function getSlowQueryThreshold(): float
    {
        return self::$slowQueryThreshold;
    }

    /**
     * Reset all statistics
     */
    public static function reset(): void
    {
        self::$stats = [
            'total_connections' => 0,
            'active_connections' => 0,
            'failed_connections' => 0,
            'total_queries' => 0,
            'slow_queries' => 0,
            'total_query_time' => 0.0,
            'peak_connections' => 0,
            'last_connection_time' => null,
            'last_query_time' => null,
        ];
    }

    /**
     * Get connection health status
     */
    public static function getHealthStatus(): array
    {
        $failureRate = self::$stats['total_connections'] > 0
            ? (self::$stats['failed_connections'] / self::$stats['total_connections']) * 100
            : 0;

        $slowQueryRate = self::$stats['total_queries'] > 0
            ? (self::$stats['slow_queries'] / self::$stats['total_queries']) * 100
            : 0;

        $status = 'healthy';
        $issues = [];

        if ($failureRate > 10) {
            $status = 'critical';
            $issues[] = "High connection failure rate: {$failureRate}%";
        } elseif ($failureRate > 5) {
            $status = 'warning';
            $issues[] = "Elevated connection failure rate: {$failureRate}%";
        }

        if ($slowQueryRate > 20) {
            $status = $status === 'critical' ? 'critical' : 'warning';
            $issues[] = "High slow query rate: {$slowQueryRate}%";
        }

        return [
            'status' => $status,
            'failure_rate' => round($failureRate, 2),
            'slow_query_rate' => round($slowQueryRate, 2),
            'issues' => $issues,
        ];
    }
}
