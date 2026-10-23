<?php

declare(strict_types=1);

namespace Kaly\Tasks\Store;

use InvalidArgumentException;
use Kaly\Tasks\Connection\Connection;
use Kaly\Tasks\EncodedTask;

/**
 * SQLite task store, for an application and its workers on one host.
 *
 * SQLite serializes writes, not task runs: a reservation is a single short
 * `UPDATE … RETURNING`, then each worker runs its task without holding a lock,
 * so several workers process tasks in parallel. Leases and delays use the host
 * clock, in whole seconds.
 *
 * Requires SQLite 3.38 through pdo_sqlite. ext-sqlite3 is not supported: it
 * runs a statement again when its rows are fetched, so a reservation would
 * claim a second task. DoctrineConnection refuses it. See PHP bugs #64531
 * (fetchArray() runs the query again) and #81376 (UPDATE … RETURNING).
 *
 * The connection decides the durability and the concurrency: use a local file
 * in WAL mode, with a busy timeout (pdo_sqlite defaults to 60s), and
 * `synchronous=FULL` if a power loss must not lose the last tasks.
 */
final readonly class SqliteTaskStore implements TaskStore
{
    public function __construct(
        private Connection $connection,
        private string $table = 'kaly_tasks',
    ) {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException("Table name '{$table}' must be a lowercase identifier");
        }
    }

    /**
     * The DDL to create the table, for your migrations.
     *
     * @return list<string>
     */
    public function schema(): array
    {
        $t = $this->table;
        return [
            // AUTOINCREMENT: a purged id is never reused by a new task
            "CREATE TABLE {$t} (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                queue TEXT NOT NULL,
                name TEXT NOT NULL,
                payload TEXT NOT NULL,
                dedup_key TEXT,
                status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'done', 'failed')),
                attempts INTEGER NOT NULL DEFAULT 0,
                available_at INTEGER NOT NULL DEFAULT (unixepoch()),
                owner TEXT,
                lease_until INTEGER,
                last_error TEXT,
                created_at INTEGER NOT NULL DEFAULT (unixepoch()),
                finished_at INTEGER
            ) STRICT",
            "CREATE INDEX {$t}_pending ON {$t} (queue, available_at, id) WHERE status = 'pending'",
            "CREATE INDEX {$t}_running ON {$t} (lease_until) WHERE status = 'running'",
            "CREATE INDEX {$t}_done ON {$t} (finished_at) WHERE status = 'done'",
            "CREATE INDEX {$t}_failed ON {$t} (finished_at) WHERE status = 'failed'",
            "CREATE UNIQUE INDEX {$t}_dedup ON {$t} (name, dedup_key) WHERE dedup_key IS NOT NULL",
        ];
    }

    public function push(EncodedTask $task): bool
    {
        return $this->connection->execute(
            "INSERT INTO {$this->table} (queue, name, payload, dedup_key) VALUES (?, ?, ?, ?)
            ON CONFLICT (name, dedup_key) WHERE dedup_key IS NOT NULL DO NOTHING",
            [$task->queue, $task->name, $task->payload, $task->deduplicationKey],
        ) === 1;
    }

    public function reserve(array $queues, string $owner, int $leaseSeconds): ?ReservedTask
    {
        if ($queues === []) {
            throw new InvalidArgumentException('Reserve needs at least one queue');
        }
        if (in_array('', $queues, true)) {
            throw new InvalidArgumentException('Reserve queue names cannot be empty');
        }
        StoreValues::assertDuration($leaseSeconds, 'The lease', 1);
        $placeholders = implode(', ', array_fill(0, count($queues), '?'));
        // A writing statement takes the write lock before it reads: two
        // workers never claim the same task
        $row = $this->connection->fetchAll("UPDATE {$this->table}
            SET status = 'running', owner = ?, attempts = attempts + 1, lease_until = unixepoch() + ?
            WHERE id = (
                SELECT id FROM {$this->table}
                WHERE status = 'pending' AND queue IN ({$placeholders}) AND available_at <= unixepoch()
                ORDER BY available_at, id
                LIMIT 1
            )
            RETURNING id, name, payload, attempts", [$owner, $leaseSeconds, ...$queues])[0] ?? null;

        return $row === null ? null : StoreValues::reserved($row, $owner);
    }

    public function complete(ReservedTask $task): bool
    {
        return $this->connection->execute(
            "UPDATE {$this->table}
            SET status = 'done', owner = NULL, lease_until = NULL, last_error = NULL, finished_at = unixepoch()
            WHERE id = ? AND owner = ? AND status = 'running'",
            [$task->id, $task->owner],
        ) === 1;
    }

    public function release(ReservedTask $task, int $delaySeconds, string $error): bool
    {
        StoreValues::assertDuration($delaySeconds, 'The retry delay');
        return $this->connection->execute(
            "UPDATE {$this->table}
            SET status = 'pending', owner = NULL, lease_until = NULL, last_error = ?, available_at = unixepoch() + ?
            WHERE id = ? AND owner = ? AND status = 'running'",
            [StoreValues::error($error), $delaySeconds, $task->id, $task->owner],
        ) === 1;
    }

    public function fail(ReservedTask $task, string $error): bool
    {
        return $this->connection->execute(
            "UPDATE {$this->table}
            SET status = 'failed', owner = NULL, lease_until = NULL, last_error = ?, finished_at = unixepoch()
            WHERE id = ? AND owner = ? AND status = 'running'",
            [StoreValues::error($error), $task->id, $task->owner],
        ) === 1;
    }

    public function recover(): int
    {
        return $this->connection->execute("UPDATE {$this->table}
            SET status = 'pending', owner = NULL, lease_until = NULL, last_error = 'Lease expired'
            WHERE status = 'running' AND lease_until < unixepoch()");
    }

    public function failed(int $limit = 50): array
    {
        StoreValues::assertDuration($limit, 'The limit', 1);
        $rows = $this->connection->fetchAll(
            "SELECT id, queue, name, payload, attempts, last_error, created_at, finished_at FROM {$this->table}
            WHERE status = 'failed' ORDER BY finished_at DESC, id DESC LIMIT ?",
            [$limit],
        );
        return array_map(StoreValues::failed(...), $rows);
    }

    public function retryFailed(int $id): bool
    {
        return $this->connection->execute("UPDATE {$this->table}
            SET status = 'pending', attempts = 0, available_at = unixepoch(), finished_at = NULL
            WHERE id = ? AND status = 'failed'", [$id]) === 1;
    }

    public function deleteFailed(int $id): bool
    {
        return $this->connection->execute("DELETE FROM {$this->table} WHERE id = ? AND status = 'failed'", [$id]) === 1;
    }

    public function purge(int $olderThanSeconds, ?int $failedOlderThanSeconds = null): int
    {
        StoreValues::assertDuration($olderThanSeconds, 'The done retention');
        if ($failedOlderThanSeconds !== null) {
            StoreValues::assertDuration($failedOlderThanSeconds, 'The failed retention');
        }
        $deleted = $this->connection->execute("DELETE FROM {$this->table}
            WHERE status = 'done' AND finished_at < unixepoch() - ?", [$olderThanSeconds]);
        if ($failedOlderThanSeconds !== null) {
            $deleted += $this->connection->execute("DELETE FROM {$this->table}
                WHERE status = 'failed' AND finished_at < unixepoch() - ?", [$failedOlderThanSeconds]);
        }
        return $deleted;
    }
}
