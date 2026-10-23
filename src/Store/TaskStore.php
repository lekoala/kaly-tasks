<?php

declare(strict_types=1);

namespace Kaly\Tasks\Store;

use Kaly\Tasks\EncodedTask;

/**
 * Durable task storage with leases.
 *
 * A task is `pending`, then `running` under a lease held by an owner token,
 * then `done` or `failed`. Settling a task (complete, release, fail) only
 * applies while the caller still owns it: after its lease expired and another
 * worker took it, a late owner changes nothing. Delivery is at least once.
 *
 * Task names, queue names, deduplication keys and owner tokens must not contain
 * NUL bytes: stores reject them with InvalidArgumentException before SQL runs.
 * NUL characters in the JSON payload remain supported through JSON escapes.
 */
interface TaskStore
{
    /**
     * The DDL to create the table, for your migrations.
     *
     * @return list<string>
     */
    public function schema(): array;

    /**
     * @return bool False when a task with the same name and deduplication key is already stored
     */
    public function push(EncodedTask $task): bool;

    /**
     * Leases the next available task of the given queues to a new owner.
     *
     * @param list<string> $queues
     */
    public function reserve(array $queues, string $owner, int $leaseSeconds): ?ReservedTask;

    public function complete(ReservedTask $task): bool;

    /**
     * Makes the task available again after `$delaySeconds`.
     */
    public function release(ReservedTask $task, int $delaySeconds, string $error): bool;

    public function fail(ReservedTask $task, string $error): bool;

    /**
     * Makes running tasks whose lease expired available again.
     *
     * @return int The number of recovered tasks
     */
    public function recover(): int;

    /**
     * Dates are Unix timestamps in whole seconds.
     *
     * @return list<array{id:int,queue:string,name:string,payload:string,attempts:int,error:string|null,created_at:int,finished_at:int}>
     */
    public function failed(int $limit = 50): array;

    /**
     * Makes a failed task available again, with a fresh attempt count.
     * The previous error is kept until the task succeeds or fails again.
     */
    public function retryFailed(int $id): bool;

    /**
     * Deletes a failed task and frees its deduplication key.
     */
    public function deleteFailed(int $id): bool;

    /**
     * Deletes done tasks finished more than `$olderThanSeconds` ago, and failed
     * tasks finished more than `$failedOlderThanSeconds` ago when given.
     *
     * Done and failed tasks keep their deduplication key until purged: the
     * retention is the deduplication window. A failed task is never replaced
     * by a new dispatch with the same key unless deleted or purged.
     *
     * @return int The number of deleted tasks
     */
    public function purge(int $olderThanSeconds, ?int $failedOlderThanSeconds = null): int;
}
