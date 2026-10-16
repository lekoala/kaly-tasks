<?php

declare(strict_types=1);

namespace Kaly\Tasks;

/**
 * A registered persistable task type. Built by TaskRegistry::register().
 */
final readonly class TaskType
{
    /**
     * @param class-string $class
     */
    public function __construct(
        public string $name,
        public string $class,
        public string $queue,
        public RetryPolicy $retry,
        public ?string $deduplicateBy,
    ) {}
}
