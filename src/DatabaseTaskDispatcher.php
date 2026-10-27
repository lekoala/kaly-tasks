<?php

declare(strict_types=1);

namespace Kaly\Tasks;

use DateTimeImmutable;
use Kaly\Task\ScheduledTaskDispatcher;
use Kaly\Tasks\Store\TaskStore;

/**
 * Stores registered tasks for a worker to run.
 *
 * When `dispatch()` returns, the task is written through the store
 * connection. On the application connection inside a transaction, it becomes
 * durable with the commit and disappears with a rollback. On a dedicated
 * connection, it is durable immediately, independently of the business
 * transaction. A task already stored with the same deduplication key is
 * silently skipped, preserving its original deadline. `notBefore` is a lower
 * bound on execution, normalized by the store.
 *
 * A thrown error does not prove the insertion failed: a lost connection or an
 * ambiguous commit may have stored the task. Retrying with the same deduplication
 * key prevents duplicate stored tasks while that key is retained; it does not
 * guarantee exactly-once external effects.
 */
final readonly class DatabaseTaskDispatcher implements ScheduledTaskDispatcher
{
    public function __construct(
        private TaskStore $store,
        private TaskRegistry $registry,
    ) {}

    public function dispatch(object $task, ?DateTimeImmutable $notBefore = null): void
    {
        $this->store->push($this->registry->encode($task), $notBefore);
    }
}
