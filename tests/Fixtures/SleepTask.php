<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests\Fixtures;

final readonly class SleepTask
{
    public function __construct(
        public int $seconds,
    ) {}

    public function __invoke(): void
    {
        // A signal interrupts sleep(): the task then ends early
        sleep($this->seconds);
    }
}
