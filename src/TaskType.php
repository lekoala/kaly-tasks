<?php

declare(strict_types=1);

namespace Kaly\Tasks;

use BackedEnum;

/**
 * A registered persistable task type. Built by TaskRegistry::register().
 */
final readonly class TaskType
{
    /**
     * @param class-string $class
     * @param array<string,array{optional:bool,enum:class-string<BackedEnum>|null,scalar:string|null}> $parameters
     */
    public function __construct(
        public string $name,
        public string $class,
        public string $queue,
        public RetryPolicy $retry,
        public array $parameters,
        public ?string $deduplicateBy,
    ) {}
}
