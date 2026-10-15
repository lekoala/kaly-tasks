<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests\Fixtures;

final readonly class TypedTask
{
    /**
     * @param array<mixed> $tags
     */
    public function __construct(
        public string $id,
        public int $count,
        public float $ratio,
        public bool $urgent,
        public array $tags,
        public Priority $priority,
        public ?string $note = null,
        public int $retries = 3,
    ) {}

    public function __invoke(): void {}
}
