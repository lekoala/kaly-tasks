<?php

declare(strict_types=1);

namespace Kaly\Tasks;

/**
 * One attempt of a decoded task, as seen by the worker's execution wrapper.
 */
final readonly class TaskRun
{
    /**
     * @param int $id Stored task id
     * @param string $name Registered task name
     * @param int $attempt This attempt, from 1
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $queue,
        public int $attempt,
        public int $maxAttempts,
        public object $task,
    ) {}
}
