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
 * LoopParticipant built from closures (lets a class keep its loop methods private)
 */
final class CallbackParticipant implements LoopParticipant
{
    /**
     * @param \Closure(): bool       $pending
     * @param \Closure(): int        $tick
     * @param \Closure(float): void  $wait
     */
    public function __construct(
        private \Closure $pending,
        private \Closure $tick,
        private \Closure $wait
    ) {
    }

    public function pending(): bool
    {
        return ($this->pending)();
    }

    public function tick(): int
    {
        return ($this->tick)();
    }

    public function wait(float $seconds): void
    {
        ($this->wait)($seconds);
    }
}
