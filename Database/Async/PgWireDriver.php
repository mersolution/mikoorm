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
 * PostgreSQL async queries without the pgsql extension: the built-in PHP client
 * (PgWireLink) talks the PostgreSQL protocol over a socket.
 *
 * Same behaviour as PgsqlDriver: values are sent as real parameters ($1..$n) and
 * int2/int4/int8/oid become int, boolean bool, bytea a binary string (like PDO).
 */
final class PgWireDriver extends AsyncDriver
{
    /**
     * Text parameters cannot contain NUL bytes: such queries run on the main connection
     */
    public function canRun(string $sql, array $bindings): bool
    {
        if (str_contains($sql, "\0")) {
            return false;
        }
        foreach ($bindings as $value) {
            if (is_string($value) && str_contains($value, "\0")) {
                return false;
            }
        }
        return true;
    }

    protected function openLink(): object
    {
        return PgWireLink::open($this->config);
    }

    protected function send(object $link, AsyncJob $job): void
    {
        /** @var PgWireLink $link */
        [$sql, $params] = SqlBinder::numbered($job->sql, $job->bindings);
        $link->send($sql, $params);
    }

    protected function poll(array $links, float $timeout): array
    {
        $ready = $this->readyLinks($links);
        if ($ready !== [] || $timeout <= 0) {
            return $ready;
        }

        $read = [];
        foreach ($links as $link) {
            /** @var PgWireLink $link */
            $socket = $link->socket();
            if (!is_resource($socket)) {
                return $links; // broken: fetch() reports it
            }
            $read[] = $socket;
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
        /** @var PgWireLink $link */
        $error = $link->error();
        if ($error !== null) {
            throw QueryException::fromDriver($job->sql, $job->bindings, PgWireLink::errorText($error), 7, $error['C'] ?? null);
        }
        if (!$link->finished()) {
            throw QueryException::fromDriver($job->sql, $job->bindings, 'Lost the connection to the PostgreSQL server.', 7, '08006');
        }
        return $link->rows();
    }

    protected function cancel(object $link, AsyncJob $job): void
    {
        /** @var PgWireLink $link */
        if (!$link->cancel()) {
            throw new \RuntimeException('The cancelled query did not stop.');
        }
    }

    protected function healthy(object $link): bool
    {
        /** @var PgWireLink $link */
        return $link->healthy();
    }

    protected function closeLink(object $link): void
    {
        /** @var PgWireLink $link */
        $link->close();
    }

    /**
     * @param list<PgWireLink> $links
     * @return list<PgWireLink>
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
