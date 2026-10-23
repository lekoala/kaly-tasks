<?php

declare(strict_types=1);

namespace Kaly\Tasks;

use InvalidArgumentException;

/**
 * Bounded exponential backoff: `delay * multiplier^(attempt - 1)` seconds,
 * capped at `maxDelay`. After `maxAttempts` attempts the task fails for good.
 */
final readonly class RetryPolicy
{
    public function __construct(
        public int $maxAttempts = 5,
        public int $delay = 10,
        public float $multiplier = 2.0,
        public int $maxDelay = 3600,
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('A task needs at least one attempt');
        }
        if ($delay < 0 || $maxDelay < $delay || $maxDelay > 2_147_483_647) {
            throw new InvalidArgumentException('Retry delays must satisfy 0 <= delay <= maxDelay <= 2147483647');
        }
        if (!is_finite($multiplier) || $multiplier < 1) {
            throw new InvalidArgumentException('The retry multiplier must be finite and at least 1');
        }
    }

    /**
     * A single attempt, no retry.
     */
    public static function never(): self
    {
        return new self(maxAttempts: 1);
    }

    /**
     * Seconds to wait before the attempt following `$attempt`.
     */
    public function delayAfter(int $attempt): int
    {
        // The power overflows to INF after many attempts, and 0 * INF is NAN
        if ($this->delay === 0) {
            return 0;
        }
        return (int) min($this->maxDelay, $this->delay * ($this->multiplier ** max(0, $attempt - 1)));
    }
}
