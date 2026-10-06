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

use Miko\Core\Async\Deferred;

/**
 * @internal one queued / running async query
 */
final class AsyncJob
{
    public Deferred $deferred;
    public ?float $startedAt = null;

    public function __construct(
        public readonly string $sql,
        public readonly array $bindings,
        public readonly ?float $deadline,
        public readonly float $timeout
    ) {
        $this->deferred = new Deferred();
    }
}
