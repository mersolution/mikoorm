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

use Miko\Database\ConnectionInterface;

/**
 * Health Check - Database connection health monitoring
 */
class HealthCheck
{
    private ConnectionInterface $connection;
    private array $checks = [];

    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Run all health checks
     */
    public function run(): array
    {
        $this->checks = [];

        $this->checkConnection();
        $this->checkLatency();
        $this->checkThreads();
        $this->checkSlowQueries();

        return $this->getResults();
    }

    private function checkConnection(): void
    {
        try {
            $this->connection->execute('SELECT 1')->all();
            $this->checks['connection'] = [
                'status' => 'ok',
                'message' => 'Database connection is healthy',
            ];
        } catch (\Throwable $e) {
            $this->checks['connection'] = [
                'status' => 'error',
                'message' => 'Connection failed: ' . $e->getMessage(),
            ];
        }
    }

    private function checkLatency(): void
    {
        $start = hrtime(true);

        try {
            $this->connection->execute('SELECT 1')->all();
            $latency = round((hrtime(true) - $start) / 1e6, 2);
            $status = $latency < 10 ? 'ok' : ($latency < 100 ? 'warning' : 'error');

            $this->checks['latency'] = [
                'status' => $status,
                'value' => $latency,
                'unit' => 'ms',
                'message' => "Query latency: {$latency}ms",
            ];
        } catch (\Throwable $e) {
            $this->checks['latency'] = [
                'status' => 'error',
                'message' => 'Latency check failed',
            ];
        }
    }

    /**
     * Active connections vs. max_connections (MySQL / MariaDB / PostgreSQL)
     */
    private function checkThreads(): void
    {
        try {
            $driver = $this->connection->getDriverName();

            if ($driver === 'mysql') {
                $threads = (int) ($this->connection->execute("SHOW STATUS LIKE 'Threads_connected'")->first()['Value'] ?? 0);
                $max = (int) ($this->connection->execute("SHOW VARIABLES LIKE 'max_connections'")->first()['Value'] ?? 151);
            } elseif ($driver === 'pgsql') {
                $threads = (int) ($this->connection->execute('SELECT count(*) AS c FROM pg_stat_activity')->first()['c'] ?? 0);
                $max = (int) ($this->connection->execute('SHOW max_connections')->first()['max_connections'] ?? 100);
            } else {
                $this->checks['threads'] = ['status' => 'unknown', 'message' => 'Thread check not available for ' . $driver];
                return;
            }

            $usage = $max > 0 ? ($threads / $max) * 100 : 0;
            $status = $usage < 70 ? 'ok' : ($usage < 90 ? 'warning' : 'error');

            $this->checks['threads'] = [
                'status' => $status,
                'active' => $threads,
                'max' => $max,
                'usage_percent' => round($usage, 2),
                'message' => "Active connections: {$threads}/{$max}",
            ];
        } catch (\Throwable $e) {
            $this->checks['threads'] = [
                'status' => 'unknown',
                'message' => 'Thread check not available',
            ];
        }
    }

    /**
     * Slow query counter (MySQL / MariaDB)
     */
    private function checkSlowQueries(): void
    {
        try {
            if ($this->connection->getDriverName() !== 'mysql') {
                $this->checks['slow_queries'] = ['status' => 'unknown', 'message' => 'Slow query check not available'];
                return;
            }

            $slowQueries = (int) ($this->connection->execute("SHOW STATUS LIKE 'Slow_queries'")->first()['Value'] ?? 0);
            $status = $slowQueries === 0 ? 'ok' : ($slowQueries < 10 ? 'warning' : 'error');

            $this->checks['slow_queries'] = [
                'status' => $status,
                'count' => $slowQueries,
                'message' => "Slow queries: {$slowQueries}",
            ];
        } catch (\Throwable $e) {
            $this->checks['slow_queries'] = [
                'status' => 'unknown',
                'message' => 'Slow query check not available',
            ];
        }
    }

    public function getResults(): array
    {
        $overallStatus = 'ok';

        foreach ($this->checks as $check) {
            if ($check['status'] === 'error') {
                $overallStatus = 'error';
                break;
            }
            if ($check['status'] === 'warning') {
                $overallStatus = 'warning';
            }
        }

        return [
            'status' => $overallStatus,
            'timestamp' => date('Y-m-d H:i:s'),
            'checks' => $this->checks,
        ];
    }

    /**
     * Quick health check - just connection
     */
    public function ping(): bool
    {
        try {
            $this->connection->execute('SELECT 1')->all();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
