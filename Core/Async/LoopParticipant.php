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
 * Something that runs I/O in the background (async database driver, HTTP client)
 * and is driven by EventLoop while a Future is awaited.
 */
interface LoopParticipant
{
    /** Work is queued or running */
    public function pending(): bool;

    /**
     * Non-blocking step: start queued work, collect finished work, handle timeouts.
     *
     * @return int number of finished / started items (0 = nothing happened)
     */
    public function tick(): int;

    /**
     * Block until I/O activity, a timer of this participant, or $seconds
     */
    public function wait(float $seconds): void;
}
