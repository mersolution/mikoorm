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
 * Result of work that is already running (a database query, an HTTP request).
 *
 *   $future = User::query()->getAsync();   // the query is sent now
 *   ...                                     // other work meanwhile
 *   $users = $future->await();              // wait for the result (rethrows its error)
 *
 * then() / catch() return new futures; a callback may return another Future.
 */
final class Future
{
    private const PENDING = 0;
    private const FULFILLED = 1;
    private const REJECTED = 2;

    private int $state = self::PENDING;
    private mixed $value = null;
    private ?\Throwable $error = null;

    /** @var list<callable(): void> */
    private array $callbacks = [];

    // ========================================
    // Creation
    // ========================================

    public static function resolved(mixed $value = null): self
    {
        $future = new self();
        $future->complete(true, $value);
        return $future;
    }

    public static function rejected(\Throwable $error): self
    {
        $future = new self();
        $future->complete(false, $error);
        return $future;
    }

    /**
     * Run a callable now; its result (or exception) becomes the future
     */
    public static function call(callable $callback): self
    {
        try {
            return self::resolved($callback());
        } catch (\Throwable $e) {
            return self::rejected($e);
        }
    }

    /**
     * Future of all values (keys kept); rejects with the first error in key order once every future is settled
     *
     * @param array<array-key, mixed> $items futures or plain values
     */
    public static function all(array $items): self
    {
        return self::settleAll($items)->then(static function (array $settled) {
            $values = [];
            foreach ($settled as $key => $result) {
                if ($result['status'] === 'rejected') {
                    throw $result['reason'];
                }
                $values[$key] = $result['value'];
            }
            return $values;
        });
    }

    /**
     * Future of ['status' => 'fulfilled', 'value' => ..] / ['status' => 'rejected', 'reason' => Throwable] per key
     *
     * @param array<array-key, mixed> $items futures or plain values
     */
    public static function settleAll(array $items): self
    {
        $deferred = new Deferred();
        $results = [];
        $remaining = count($items);

        if ($remaining === 0) {
            $deferred->resolve([]);
            return $deferred->future();
        }

        foreach ($items as $key => $item) {
            $results[$key] = null;
            $future = $item instanceof self ? $item : self::resolved($item);

            $future->onSettled(static function (self $settled) use ($key, &$results, &$remaining, $items, $deferred): void {
                $results[$key] = $settled->state === self::FULFILLED
                    ? ['status' => 'fulfilled', 'value' => $settled->value]
                    : ['status' => 'rejected', 'reason' => $settled->error];

                if (--$remaining === 0) {
                    // input key order, not completion order
                    $ordered = [];
                    foreach ($items as $k => $_) {
                        $ordered[$k] = $results[$k];
                    }
                    $deferred->resolve($ordered);
                }
            });
        }

        return $deferred->future();
    }

    // ========================================
    // State
    // ========================================

    public function isPending(): bool
    {
        return $this->state === self::PENDING;
    }

    /** Finished, with a value or an error */
    public function isReady(): bool
    {
        return $this->state !== self::PENDING;
    }

    public function isFulfilled(): bool
    {
        return $this->state === self::FULFILLED;
    }

    public function isRejected(): bool
    {
        return $this->state === self::REJECTED;
    }

    /**
     * Wait for the result; runs the other pending work (queries, HTTP requests) meanwhile.
     * Throws the error of a failed future.
     */
    public function await(): mixed
    {
        if ($this->state === self::PENDING) {
            EventLoop::runUntil(fn(): bool => $this->state !== self::PENDING);
        }

        if ($this->state === self::REJECTED) {
            throw $this->error;
        }

        return $this->value;
    }

    // ========================================
    // Chaining
    // ========================================

    /**
     * New future with the callback result; $onRejected handles an error (return a value to recover)
     */
    public function then(?callable $onFulfilled, ?callable $onRejected = null): self
    {
        $deferred = new Deferred();

        $this->onSettled(static function (self $settled) use ($deferred, $onFulfilled, $onRejected): void {
            $callback = $settled->state === self::FULFILLED ? $onFulfilled : $onRejected;

            if ($callback === null) {
                $settled->state === self::FULFILLED ? $deferred->resolve($settled->value) : $deferred->reject($settled->error);
                return;
            }

            try {
                $deferred->resolve($callback($settled->state === self::FULFILLED ? $settled->value : $settled->error));
            } catch (\Throwable $e) {
                $deferred->reject($e);
            }
        });

        return $deferred->future();
    }

    public function catch(callable $onRejected): self
    {
        return $this->then(null, $onRejected);
    }

    /**
     * Run a callback when the future settles (value or error), keep the result
     */
    public function finally(callable $callback): self
    {
        return $this->then(
            static function (mixed $value) use ($callback) {
                $callback();
                return $value;
            },
            static function (\Throwable $error) use ($callback) {
                $callback();
                throw $error;
            }
        );
    }

    // ========================================
    // Internals (used by Deferred)
    // ========================================

    /**
     * @internal settle the future; a Future value is followed
     */
    public function complete(bool $success, mixed $valueOrError): void
    {
        if ($this->state !== self::PENDING) {
            return;
        }

        if ($success && $valueOrError instanceof self) {
            if ($valueOrError === $this) {
                $this->complete(false, new \LogicException('A future cannot resolve to itself.'));
                return;
            }
            $valueOrError->onSettled(function (self $inner): void {
                $this->complete($inner->state === self::FULFILLED, $inner->state === self::FULFILLED ? $inner->value : $inner->error);
            });
            return;
        }

        if ($success) {
            $this->state = self::FULFILLED;
            $this->value = $valueOrError;
        } else {
            $this->state = self::REJECTED;
            $this->error = $valueOrError instanceof \Throwable ? $valueOrError : new \RuntimeException((string) $valueOrError);
        }

        $callbacks = $this->callbacks;
        $this->callbacks = [];
        foreach ($callbacks as $callback) {
            $callback();
        }
    }

    private function onSettled(callable $callback): void
    {
        if ($this->state !== self::PENDING) {
            $callback($this);
            return;
        }
        $this->callbacks[] = fn() => $callback($this);
    }
}
