<?php

declare(strict_types=1);

namespace Kaly\Tasks\Store;

/**
 * A task leased to a worker. Only the owner token that reserved it can settle it.
 */
final readonly class ReservedTask
{
    public function __construct(
        public int $id,
        public string $name,
        public string $payload,
        public int $attempts,
        public string $owner,
    ) {}
}
