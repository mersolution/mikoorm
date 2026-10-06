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
 * Minimal event loop: drives every LoopParticipant (database drivers, HTTP clients)
 * while a Future is awaited. PHP stays single threaded; the parallelism comes from
 * the servers working on several requests at the same time.
 */
final class EventLoop
{
    /** @var array<int, LoopParticipant> */
    private static array $participants = [];

    public static function register(LoopParticipant $participant): void
    {
        self::$participants[spl_object_id($participant)] = $participant;
    }

    public static function unregister(LoopParticipant $participant): void
    {
        unset(self::$participants[spl_object_id($participant)]);
    }

    /**
     * Run the participants until $done() returns true
     *
     * @param callable(): bool $done
     */
    public static function runUntil(callable $done): void
    {
        while (!$done()) {
            $progress = 0;

            foreach (self::$participants as $id => $participant) {
                if (!isset(self::$participants[$id])) {
                    continue; // removed by a callback of another participant
                }

                $progress += $participant->tick();
                if ($done()) {
                    return;
                }
            }

            if ($progress > 0) {
                continue;
            }

            $active = array_filter(self::$participants, static fn(LoopParticipant $p): bool => $p->pending());
            if ($active === []) {
                throw new \LogicException('The awaited future can never finish: no query or request is running for it.');
            }

            if (count($active) === 1) {
                reset($active)->wait(0.5);
            } else {
                // several sources (database + HTTP): short turns so none of them waits on the other
                foreach ($active as $participant) {
                    $participant->wait(0.002);
                }
            }
        }
    }
}
