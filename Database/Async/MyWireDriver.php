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
 * MySQL / MariaDB async queries without mysqli: the built-in PHP client (MyWireLink).
 *
 * Same behaviour as MysqliDriver: values are inlined as escaped literals, int / float
 * columns come back as int / float, errors have the same codes and SQLSTATE.
 */
final class MyWireDriver extends AsyncDriver
{
    protected function openLink(): object
    {
        return MyWireLink::open($this->config);
    }

    protected function send(object $link, AsyncJob $job): void
    {
        /** @var MyWireLink $link */
        $link->send(SqlBinder::inlined($job->sql, $job->bindings, $link->quote(...)));
    }

    protected function poll(array $links, float $timeout): array
    {
        $ready = $this->readyLinks($links);
        if ($ready !== [] || $timeout <= 0) {
            return $ready;
        }

        $read = [];
        foreach ($links as $link) {
            /** @var MyWireLink $link */
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
        /** @var MyWireLink $link */
        $error = $link->error();
        if ($error !== null) {
            throw QueryException::fromDriver($job->sql, $job->bindings, $error['message'], $error['code'], $error['state']);
        }
        if (!$link->finished()) {
            throw QueryException::fromDriver($job->sql, $job->bindings, 'Lost connection to MySQL server during query', 2013, 'HY000');
        }
        return $link->rows();
    }

    protected function cancel(object $link, AsyncJob $job): void
    {
        /** @var MyWireLink $link */
        $this->connection->getPdo()->exec('KILL QUERY ' . $link->threadId());

        // the interrupted statement (error 1317) still has to be read before the link is reused
        if (!$link->drain()) {
            throw new \RuntimeException('The cancelled query did not stop.');
        }
    }

    protected function healthy(object $link): bool
    {
        /** @var MyWireLink $link */
        return $link->healthy();
    }

    protected function closeLink(object $link): void
    {
        /** @var MyWireLink $link */
        $link->close();
    }

    /**
     * @param list<MyWireLink> $links
     * @return list<MyWireLink>
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
