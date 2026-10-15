<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests\Fixtures;

final readonly class RecordTask
{
    public function __construct(
        public string $entry,
    ) {}

    public function __invoke(Journal $journal): void
    {
        $journal->record($this->entry);
    }
}
