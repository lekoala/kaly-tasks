<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests\Fixtures;

use Kaly\Tasks\PermanentFailure;
use Kaly\Tasks\RetryLater;

/**
 * Fails permanently, or asks for a retry after `$retryAfter` seconds.
 */
final readonly class FailingTask
{
    public function __construct(
        public ?int $retryAfter = null,
    ) {}

    public function __invoke(Journal $journal): void
    {
        $journal->entries[] = 'ran';
        if ($this->retryAfter === null) {
            throw new PermanentFailure('Invalid data');
        }
        throw new RetryLater($this->retryAfter, 'Rate limited');
    }
}
