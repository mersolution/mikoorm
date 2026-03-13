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

    /**
     * Check if connection is alive
     */
    private function checkConnection(): void
    {
        try {
            $result = $this->connection->execute("SELECT 1")->fetchAll();
            $this->checks['connection'] = [
                'status' => 'ok',
                'message' => 'Database connection is healthy'
            ];
        } catch (\Throwable $e) {
            $this->checks['connection'] = [
                'status' => 'error',
                'message' => 'Connection failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Check query latency
     */
    private function checkLatency(): void
    {
        $start = microtime(true);
        
        try {
            $this->connection->execute("SELECT 1");
            $latency = (microtime(true) - $start) * 1000;

            $status = $latency < 10 ? 'ok' : ($latency < 100 ? 'warning' : 'error');

            $this->checks['latency'] = [
                'status' => $status,
                'value' => round($latency, 2),
                'unit' => 'ms',
                'message' => "Query latency: {$latency}ms"
            ];
        } catch (\Throwable $e) {
            $this->checks['latency'] = [
                'status' => 'error',
                'message' => 'Latency check failed'
            ];
        }
    }

    /**
     * Check active threads/connections
     */
    private function checkThreads(): void
    {
        try {
            $result = $this->connection->execute("SHOW STATUS LIKE 'Threads_connected'")->fetchAll();
            $threads = (int)($result[0]['Value'] ?? 0);

            $maxResult = $this->connection->execute("SHOW VARIABLES LIKE 'max_connections'")->fetchAll();
            $maxConnections = (int)($maxResult[0]['Value'] ?? 151);

            $usage = ($threads / $maxConnections) * 100;
            $status = $usage < 70 ? 'ok' : ($usage < 90 ? 'warning' : 'error');

            $this->checks['threads'] = [
                'status' => $status,
                'active' => $threads,
                'max' => $maxConnections,
                'usage_percent' => round($usage, 2),
                'message' => "Active connections: {$threads}/{$maxConnections}"
            ];
        } catch (\Throwable $e) {
            $this->checks['threads'] = [
                'status' => 'unknown',
                'message' => 'Thread check not available'
            ];
        }
    }

    /**
     * Check for slow queries
     */
    private function checkSlowQueries(): void
    {
        try {
            $result = $this->connection->execute("SHOW STATUS LIKE 'Slow_queries'")->fetchAll();
            $slowQueries = (int)($result[0]['Value'] ?? 0);

            $status = $slowQueries === 0 ? 'ok' : ($slowQueries < 10 ? 'warning' : 'error');

            $this->checks['slow_queries'] = [
                'status' => $status,
                'count' => $slowQueries,
                'message' => "Slow queries: {$slowQueries}"
            ];
        } catch (\Throwable $e) {
            $this->checks['slow_queries'] = [
                'status' => 'unknown',
                'message' => 'Slow query check not available'
            ];
        }
    }

    /**
     * Get check results
     */
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
            'checks' => $this->checks
        ];
    }

    /**
     * Quick health check - just connection
     */
    public function ping(): bool
    {
        try {
            $this->connection->execute("SELECT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
