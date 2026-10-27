<?php

declare(strict_types=1);

namespace Kaly\Tasks\Store;

use DateTimeImmutable;

/**
 * Queue state from one aggregate statement, under the connection's isolation
 * rules. Values may be stale as soon as the statement returns; separate calls
 * do not share a snapshot.
 */
final readonly class TaskStats
{
    public function __construct(
        public int $ready,
        public int $scheduled,
        public int $running,
        public int $failed,
        public ?DateTimeImmutable $earliestPendingAt,
    ) {}
}
