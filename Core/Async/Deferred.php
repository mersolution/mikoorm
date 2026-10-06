<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Core\Async;

/**
 * The writing side of a Future: the code that runs the work resolves or rejects it.
 */
final class Deferred
{
    private Future $future;

    public function __construct()
    {
        $this->future = new Future();
    }

    public function future(): Future
    {
        return $this->future;
    }

    public function resolve(mixed $value = null): void
    {
        $this->future->complete(true, $value);
    }

    public function reject(\Throwable $error): void
    {
        $this->future->complete(false, $error);
    }

    public function isPending(): bool
    {
        return $this->future->isPending();
    }
}
