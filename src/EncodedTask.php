<?php

declare(strict_types=1);

namespace Kaly\Tasks;

/**
 * A task as it is stored: a stable name, a JSON payload and its routing.
 */
final readonly class EncodedTask
{
    public function __construct(
        public string $name,
        public string $queue,
        public string $payload,
        public ?string $deduplicationKey = null,
    ) {}
}
