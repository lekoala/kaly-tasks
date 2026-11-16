<?php

declare(strict_types=1);

namespace Kaly\Tasks;

use DateTimeImmutable;

/** Durable linear sequences, without passing results between tasks. */
interface TaskSequenceDispatcher
{
    /**
     * Stores the first task and its continuation without running any task.
     * All tasks are encoded before insertion; only the first may deduplicate.
     * The optional deadline applies to the first task only.
     *
     * @param non-empty-list<object> $tasks
     * @return bool True when inserted, false when the first task's key is already stored
     */
    public function sequence(array $tasks, ?DateTimeImmutable $notBefore = null): bool;
}
