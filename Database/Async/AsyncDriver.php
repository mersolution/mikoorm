<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\Async;

use Miko\Core\Async\Async;
use Miko\Core\Async\EventLoop;
use Miko\Core\Async\Future;
use Miko\Core\Async\LoopParticipant;
use Miko\Database\Connection;
use Miko\Database\Exceptions\QueryException;
use Miko\Database\Log\QueryLogger;
use Miko\Database\Monitor\ConnectionStats;
use Miko\Log\Logger;

/**
 * Runs queries of one Connection in parallel on extra native connections ("links").
 *
 * - at most "max_connections" links, opened on demand and reused for the whole request
 * - queries beyond the limit wait in a queue
 * - "timeout" cancels a query on the server (KILL QUERY / pg_cancel_query)
 */
abstract class AsyncDriver implements LoopParticipant
{
    protected Connection $connection;
    protected array $config;

    /** @var list<object> links without a running query */
    private array $idle = [];

    /** @var array<int, array{0: object, 1: AsyncJob}> link id => [link, job] */
    private array $busy = [];

    /** @var list<AsyncJob> */
    private array $queue = [];

    private int $open = 0;

    /** most links the server accepted (null = no failed connect yet) */
    private ?int $cap = null;
    private float $cappedAt = 0.0;

    public function __construct(Connection $connection, array $config)
    {
        $this->connection = $connection;
        $this->config = $config;
    }

    /**
     * Driver for a connection, or null when it cannot run queries in parallel.
     *
     * The PHP extension (mysqli / pgsql) is used when it is loaded, otherwise the built-in
     * PHP client; SQL Server always uses the built-in TDS client. Settings "mysql_driver" /
     * "pgsql_driver" / "sqlsrv_driver" = "extension" / "php" force one ("extension" on SQL
     * Server: run one by one).
     */
    public static function for(Connection $connection, array $config): ?self
    {
        $driver = $connection->getDriverName();
        [$extension, $native, $builtIn] = match ($driver) {
            'mysql' => ['mysqli', MysqliDriver::class, MyWireLink::supportsCharset($config['charset'] ?? null) ? MyWireDriver::class : null],
            'pgsql' => ['pgsql', PgsqlDriver::class, PgWireDriver::class],
            'sqlsrv' => [null, null, TdsLink::supports($config) ? TdsDriver::class : null],
            default => [null, null, null],
        };

        $native = $extension !== null && extension_loaded($extension) ? $native : null;
        $class = match (strtolower((string) Async::setting($driver . '_driver', 'auto'))) {
            'php' => $builtIn,
            'extension' => $native,
            default => $native ?? $builtIn,
        };

        return $class !== null ? new $class($connection, $config) : null;
    }

    /**
     * Queue a query; the Future resolves to its rows
     */
    public function submit(string $sql, array $bindings): Future
    {
        $timeout = max(0.0, (float) Async::setting('timeout', 0));
        $job = new AsyncJob($sql, $bindings, $timeout > 0 ? microtime(true) + $timeout : null, $timeout);

        $this->queue[] = $job;
        EventLoop::register($this);
        $this->dispatch();

        return $job->deferred->future();
    }

    /**
     * Whether this query can run here (otherwise it runs on the main connection)
     */
    public function canRun(string $sql, array $bindings): bool
    {
        return true;
    }

    // ========================================
    // LoopParticipant
    // ========================================

    public function pending(): bool
    {
        return $this->busy !== [] || $this->queue !== [];
    }

    public function tick(): int
    {
        $progress = $this->collect(0.0);
        $progress += $this->expire();
        $progress += $this->dispatch();

        if (!$this->pending()) {
            EventLoop::unregister($this);
        }

        return $progress;
    }

    public function wait(float $seconds): void
    {
        $deadline = null;
        foreach ($this->busy as [, $job]) {
            if ($job->deadline !== null && ($deadline === null || $job->deadline < $deadline)) {
                $deadline = $job->deadline;
            }
        }
        foreach ($this->queue as $job) {
            if ($job->deadline !== null && ($deadline === null || $job->deadline < $deadline)) {
                $deadline = $job->deadline;
            }
        }
        if ($deadline !== null) {
            $seconds = max(0.0, min($seconds, $deadline - microtime(true)));
        }

        if ($this->busy !== []) {
            $this->collect($seconds);
        } elseif ($seconds > 0) {
            usleep((int) ($seconds * 1000000));
        }
    }

    /**
     * Close every link; queued and running queries fail
     */
    public function close(): void
    {
        $error = new \RuntimeException('The database connection was closed.');
        foreach ($this->queue as $job) {
            $job->deferred->reject($error);
        }
        foreach ($this->busy as [$link, $job]) {
            $this->closeLink($link);
            $job->deferred->reject($error);
        }
        foreach ($this->idle as $link) {
            $this->closeLink($link);
        }

        $this->queue = [];
        $this->busy = [];
        $this->idle = [];
        $this->open = 0;
        EventLoop::unregister($this);
    }

    /** Number of open links (tests, monitoring) */
    public function openLinks(): int
    {
        return $this->open;
    }

    // ========================================
    // Driver specific
    // ========================================

    /** Open a native connection */
    abstract protected function openLink(): object;

    /** Send the query without waiting */
    abstract protected function send(object $link, AsyncJob $job): void;

    /**
     * Links whose result is ready (wait at most $timeout seconds)
     *
     * @param list<object> $links
     * @return list<object>
     */
    abstract protected function poll(array $links, float $timeout): array;

    /** Read the result rows (throws QueryException) */
    abstract protected function fetch(object $link, AsyncJob $job): array;

    /** Stop the running query; the link must be reusable afterwards (throw if not) */
    abstract protected function cancel(object $link, AsyncJob $job): void;

    /** Link can be reused after an error */
    abstract protected function healthy(object $link): bool;

    abstract protected function closeLink(object $link): void;

    // ========================================
    // Internals
    // ========================================

    private function dispatch(): int
    {
        $started = 0;
        $max = max(1, (int) Async::setting('max_connections', 4));

        // a failed connect limits the links for a minute (long running workers try again later)
        if ($this->cap !== null && microtime(true) - $this->cappedAt > 60) {
            $this->cap = null;
        }

        // no extra connection could be opened at all: same result on the main connection
        if ($this->cap === 0 && $this->open === 0) {
            while ($this->queue !== []) {
                $this->runOnMain(array_shift($this->queue));
                $started++;
            }
            return $started;
        }
        if ($this->cap !== null) {
            $max = min($max, max(1, $this->cap));
        }

        // the limit counts running queries too, so lowering it later also limits already open links
        while ($this->queue !== [] && count($this->busy) < $max && ($this->idle !== [] || $this->open < $max)) {
            $job = array_shift($this->queue);
            $started++;

            try {
                $link = $this->idle !== [] ? array_pop($this->idle) : $this->connect();
            } catch (\Throwable $e) {
                // stop opening links; the query waits for a free one, or runs on the main connection
                $this->cap = $this->open;
                $this->cappedAt = microtime(true);
                Logger::connection('Async connection failed, ' . ($this->open > 0
                    ? "continuing with {$this->open} connection(s)"
                    : 'queries run on the main connection') . ': ' . $e->getMessage(), [], 'WARNING');
                if ($this->open > 0) {
                    array_unshift($this->queue, $job);
                    $started--;
                    break;
                }
                $this->runOnMain($job);
                continue;
            }

            try {
                $job->startedAt = microtime(true);
                $this->send($link, $job);
                $this->busy[spl_object_id($link)] = [$link, $job];
            } catch (\Throwable $e) {
                $this->release($link);
                $this->fail($job, $e);
            }
        }

        return $started;
    }

    private function connect(): object
    {
        $link = $this->openLink();
        $this->open++;
        ConnectionStats::recordConnection();
        return $link;
    }

    private function collect(float $timeout): int
    {
        if ($this->busy === []) {
            return 0;
        }

        $ready = $this->poll(array_values(array_map(static fn(array $entry) => $entry[0], $this->busy)), $timeout);
        $finished = 0;

        foreach ($ready as $link) {
            $id = spl_object_id($link);
            if (!isset($this->busy[$id])) {
                continue; // already handled by a nested await
            }

            [, $job] = $this->busy[$id];
            unset($this->busy[$id]);
            $finished++;

            try {
                $rows = $this->fetch($link, $job);
            } catch (\Throwable $e) {
                $this->release($link);
                $this->fail($job, $e);
                continue;
            }

            $this->release($link);
            $elapsedMs = (microtime(true) - (float) $job->startedAt) * 1000;
            QueryLogger::log($job->sql, $job->bindings, $elapsedMs, true);
            ConnectionStats::recordQuery($elapsedMs / 1000);
            $job->deferred->resolve($rows);
        }

        return $finished;
    }

    /**
     * Cancel queries past their deadline
     */
    private function expire(): int
    {
        $now = microtime(true);
        $expired = 0;

        foreach ($this->queue as $index => $job) {
            if ($job->deadline !== null && $job->deadline <= $now) {
                unset($this->queue[$index]);
                $this->fail($job, AsyncTimeoutException::after($job->sql, $job->bindings, $job->timeout));
                $expired++;
            }
        }
        $this->queue = array_values($this->queue);

        foreach ($this->busy as $id => [$link, $job]) {
            if ($job->deadline === null || $job->deadline > $now) {
                continue;
            }

            unset($this->busy[$id]);
            try {
                $this->cancel($link, $job);
                $this->idle[] = $link;
            } catch (\Throwable) {
                $this->drop($link);
            }
            $this->fail($job, AsyncTimeoutException::after($job->sql, $job->bindings, $job->timeout));
            $expired++;
        }

        return $expired;
    }

    /**
     * Back to the idle list, or closed when the link is broken
     */
    private function release(object $link): void
    {
        if ($this->healthy($link)) {
            $this->idle[] = $link;
        } else {
            $this->drop($link);
        }
    }

    private function drop(object $link): void
    {
        $this->closeLink($link);
        $this->open = max(0, $this->open - 1);
        ConnectionStats::recordRelease();
    }

    private function runOnMain(AsyncJob $job): void
    {
        try {
            $job->deferred->resolve($this->connection->execute($job->sql, $job->bindings)->all());
        } catch (\Throwable $e) {
            $job->deferred->reject($e);
        }
    }

    private function fail(AsyncJob $job, \Throwable $error): void
    {
        if ($error instanceof QueryException) {
            Logger::logQuery($job->sql, $job->bindings, $error->getMessage());
        }
        $job->deferred->reject($error);
    }
}
