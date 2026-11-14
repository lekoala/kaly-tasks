<?php

declare(strict_types=1);

namespace Kaly\Tasks;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Thrown by a running task that must wait a given delay before its next
 * attempt, such as a remote API answering 429 with `Retry-After`.
 *
 * The delay replaces the retry policy's backoff and is not capped by its
 * `maxDelay`, but the task still fails once it exhausts `maxAttempts`.
 */
class RetryLater extends RuntimeException
{
    /**
     * @param int $seconds Delay before the next attempt, measured with the store clock
     */
    public function __construct(
        public readonly int $seconds,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        if ($seconds < 0 || $seconds > 2_147_483_647) {
            throw new InvalidArgumentException('The retry delay must be between 0 and 2147483647 seconds');
        }
        parent::__construct($message, 0, $previous);
    }
}
