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

use Miko\Database\Exceptions\QueryException;

/**
 * SQL Server async queries with the built-in TDS client (TdsLink); pdo_sqlsrv itself
 * has no async API.
 *
 * Values are sent as sp_executesql parameters (int / bigint / nvarchar like pdo_sqlsrv),
 * results are formatted like pdo_sqlsrv with numeric types. Columns the client does not
 * convert (sql_variant, CLR types, unknown code pages) are read again on the main connection.
 */
final class TdsDriver extends AsyncDriver
{
    /**
     * Text that is not valid UTF-8 fails in pdo_sqlsrv: run it there for the same error
     */
    public function canRun(string $sql, array $bindings): bool
    {
        if (!mb_check_encoding($sql, 'UTF-8')) {
            return false;
        }
        foreach ($bindings as $value) {
            if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                return false;
            }
        }
        return true;
    }

    protected function openLink(): object
    {
        return TdsLink::open($this->config);
    }

    protected function send(object $link, AsyncJob $job): void
    {
        /** @var TdsLink $link */
        [$sql, $values] = SqlBinder::marked($job->sql, 'sqlsrv', $job->bindings, '@P');
        $link->send($sql, $values);
    }

    protected function poll(array $links, float $timeout): array
    {
        $ready = $this->readyLinks($links);
        if ($ready !== [] || $timeout <= 0) {
            return $ready;
        }

        $read = [];
        foreach ($links as $link) {
            /** @var TdsLink $link */
            $streams = $link->streams();
            if ($streams === []) {
                return $links; // broken: fetch() reports it
            }
            // with TLS also the local pair: records cross it asynchronously
            array_push($read, ...$streams);
        }

        $write = $except = null;
        $seconds = (int) floor($timeout);
        if (@stream_select($read, $write, $except, $seconds, (int) round(($timeout - $seconds) * 1000000)) === false) {
            usleep(1000);
        }

        return $this->readyLinks($links);
    }

    protected function fetch(object $link, AsyncJob $job): array
    {
        /** @var TdsLink $link */
        $error = $link->error();
        if ($error !== null) {
            throw QueryException::fromDriver($job->sql, $job->bindings, $error['message'], $error['number'], $error['state']);
        }
        if (!$link->finished()) {
            throw QueryException::fromDriver($job->sql, $job->bindings, 'Lost the connection to SQL Server.', 0, '08S01');
        }
        if ($link->unsupported() !== null) {
            $link->rows();
            return $this->connection->execute($job->sql, $job->bindings)->all();
        }
        return $link->rows();
    }

    protected function cancel(object $link, AsyncJob $job): void
    {
        /** @var TdsLink $link */
        if (!$link->cancel()) {
            throw new \RuntimeException('The cancelled query did not stop.');
        }
    }

    protected function healthy(object $link): bool
    {
        /** @var TdsLink $link */
        return $link->healthy();
    }

    protected function closeLink(object $link): void
    {
        /** @var TdsLink $link */
        $link->close();
    }

    /**
     * @param list<TdsLink> $links
     * @return list<TdsLink>
     */
    private function readyLinks(array $links): array
    {
        $ready = [];
        foreach ($links as $link) {
            if ($link->consume()) {
                $ready[] = $link;
            }
        }
        return $ready;
    }
}
