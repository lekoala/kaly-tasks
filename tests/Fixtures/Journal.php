<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests\Fixtures;

use RuntimeException;

/**
 * Records what tasks did, and fails on demand.
 */
final class Journal
{
    /**
     * @var list<string>
     */
    public array $entries = [];

    public function record(string $entry): void
    {
        if (str_starts_with($entry, 'fail')) {
            throw new RuntimeException("Cannot record {$entry}");
        }
        $this->entries[] = $entry;
    }
}
