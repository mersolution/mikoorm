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
 * An async query ran longer than the "timeout" setting and was cancelled on the server
 */
class AsyncTimeoutException extends QueryException
{
    public static function after(string $sql, array $bindings, float $seconds): self
    {
        $exception = new self(sprintf('Query cancelled after %s s (async timeout).', rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.')));
        $exception->setSql($sql);
        $exception->setBindings($bindings);
        $exception->sqlState = 'HYT00';
        return $exception;
    }
}
