<?php

declare(strict_types=1);

namespace Kaly\Tasks\Store;

/**
 * A task leased to a worker. Only the owner token that reserved it can settle it.
 */
final readonly class ReservedTask
{
    /** @param string|null $chain Raw continuation JSON, validated by the worker before invocation */
    public function __construct(
        public int $id,
        public string $name,
        public string $queue,
        public string $payload,
        public int $attempts,
        public string $owner,
        public ?string $chain = null,
    ) {}
}
