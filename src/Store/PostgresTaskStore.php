<?php

declare(strict_types=1);

namespace Kaly\Tasks\Store;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kaly\Tasks\Connection\Connection;
use Kaly\Tasks\EncodedTask;
use UnexpectedValueException;

/**
 * PostgreSQL task store.
 *
 * Workers reserve with `FOR UPDATE SKIP LOCKED` in a single statement, so any
 * number of them can poll the same table without blocking each other. Leases
 * and delays use the database clock: workers on several hosts share one time
 * authority.
 *
 * Payloads are stored as JSON, not JSONB: the text is kept verbatim, with the
 * key order of PHP arrays and `\u0000` escapes that JSONB would drop or reject.
 */
final readonly class PostgresTaskStore implements TaskStore
{
    // 63 bytes minus the longest index suffix, "_pending"
    private const MAX_TABLE_LENGTH = 55;

    public function __construct(
        private Connection $connection,
        private string $table = 'kaly_tasks',
    ) {
        if (preg_match('/^(?:[a-z_][a-z0-9_]*\.)?[a-z_][a-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException("Table name '{$table}' must be a lowercase identifier, optionally schema-qualified");
        }
        // PostgreSQL truncates identifiers to 63 bytes: index names must stay distinct
        if (strlen($table) > self::MAX_TABLE_LENGTH) {
            throw new InvalidArgumentException("Table name '{$table}' must be at most " . self::MAX_TABLE_LENGTH . ' characters');
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
        $prefix = str_replace('.', '_', $t);
        return [
            "CREATE TABLE {$t} (
                id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                queue TEXT NOT NULL,
                name TEXT NOT NULL,
                payload JSON NOT NULL,
                dedup_key TEXT,
                status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'done', 'failed')),
                attempts INTEGER NOT NULL DEFAULT 0,
                available_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                owner TEXT,
                lease_until TIMESTAMPTZ,
                last_error TEXT,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                finished_at TIMESTAMPTZ
            )",
            "CREATE INDEX {$prefix}_pending ON {$t} (queue, available_at, id) WHERE status = 'pending'",
            "CREATE INDEX {$prefix}_running ON {$t} (lease_until) WHERE status = 'running'",
            "CREATE INDEX {$prefix}_done ON {$t} (finished_at) WHERE status = 'done'",
            "CREATE INDEX {$prefix}_failed ON {$t} (finished_at) WHERE status = 'failed'",
            "CREATE UNIQUE INDEX {$prefix}_dedup ON {$t} (name, dedup_key) WHERE dedup_key IS NOT NULL",
        ];
    }

    public function push(EncodedTask $task, ?DateTimeImmutable $notBefore = null): bool
    {
        StoreValues::assertNoNul($task->name, 'The task name');
        StoreValues::assertNoNul($task->queue, 'The queue name');
        if ($task->deduplicationKey !== null) {
            StoreValues::assertNoNul($task->deduplicationKey, 'The deduplication key');
        }
        return $this->connection->execute(
            "INSERT INTO {$this->table} (queue, name, payload, dedup_key, available_at) VALUES (?, ?, CAST(? AS JSON), ?, COALESCE(CAST(? AS TIMESTAMPTZ), now()))
            ON CONFLICT (name, dedup_key) WHERE dedup_key IS NOT NULL DO NOTHING",
            [
                $task->queue,
                $task->name,
                $task->payload,
                $task->deduplicationKey,
                $notBefore?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'),
            ],
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
        foreach ($queues as $queue) {
            StoreValues::assertNoNul($queue, 'The queue name');
        }
        StoreValues::assertNoNul($owner, 'The owner token');
        StoreValues::assertDuration($leaseSeconds, 'The lease', 1);
        $placeholders = implode(', ', array_fill(0, count($queues), '?'));
        $row = $this->connection->fetchAll("WITH next AS (
                SELECT id FROM {$this->table}
                WHERE status = 'pending' AND queue IN ({$placeholders}) AND available_at <= now()
                ORDER BY available_at, id
                LIMIT 1
                FOR UPDATE SKIP LOCKED
            )
            UPDATE {$this->table} AS t
            SET status = 'running', owner = ?, attempts = t.attempts + 1,
                lease_until = now() + CAST(? AS INTEGER) * INTERVAL '1 second'
            FROM next WHERE t.id = next.id
            RETURNING t.id, t.name, CAST(t.payload AS TEXT) AS payload, t.attempts", [...$queues, $owner, $leaseSeconds])[0] ?? null;

        return $row === null ? null : StoreValues::reserved($row, $owner);
    }

    public function complete(ReservedTask $task): bool
    {
        StoreValues::assertNoNul($task->owner, 'The owner token');
        return $this->connection->execute(
            "UPDATE {$this->table}
            SET status = 'done', owner = NULL, lease_until = NULL, last_error = NULL, finished_at = now()
            WHERE id = ? AND owner = ? AND status = 'running'",
            [$task->id, $task->owner],
        ) === 1;
    }

    public function release(ReservedTask $task, int $delaySeconds, string $error): bool
    {
        StoreValues::assertNoNul($task->owner, 'The owner token');
        StoreValues::assertDuration($delaySeconds, 'The retry delay');
        return $this->connection->execute(
            "UPDATE {$this->table}
            SET status = 'pending', owner = NULL, lease_until = NULL, last_error = ?,
                available_at = now() + CAST(? AS INTEGER) * INTERVAL '1 second'
            WHERE id = ? AND owner = ? AND status = 'running'",
            [StoreValues::error($error), $delaySeconds, $task->id, $task->owner],
        ) === 1;
    }

    public function fail(ReservedTask $task, string $error): bool
    {
        StoreValues::assertNoNul($task->owner, 'The owner token');
        return $this->connection->execute(
            "UPDATE {$this->table}
            SET status = 'failed', owner = NULL, lease_until = NULL, last_error = ?, finished_at = now()
            WHERE id = ? AND owner = ? AND status = 'running'",
            [StoreValues::error($error), $task->id, $task->owner],
        ) === 1;
    }

    public function recover(): int
    {
        return $this->connection->execute("UPDATE {$this->table}
            SET status = 'pending', owner = NULL, lease_until = NULL, last_error = 'Lease expired'
            WHERE status = 'running' AND lease_until < now()");
    }

    public function failed(int $limit = 50): array
    {
        StoreValues::assertDuration($limit, 'The limit', 1);
        $rows = $this->connection->fetchAll("SELECT id, queue, name, CAST(payload AS TEXT) AS payload, attempts, last_error,
                FLOOR(EXTRACT(EPOCH FROM created_at))::BIGINT AS created_at,
                FLOOR(EXTRACT(EPOCH FROM finished_at))::BIGINT AS finished_at FROM {$this->table} AS t
            WHERE status = 'failed' ORDER BY t.finished_at DESC, id DESC LIMIT CAST(? AS INTEGER)", [$limit]);
        return array_map(StoreValues::failed(...), $rows);
    }

    public function retryFailed(int $id): bool
    {
        return $this->connection->execute("UPDATE {$this->table}
            SET status = 'pending', attempts = 0, available_at = now(), finished_at = NULL
            WHERE id = ? AND status = 'failed'", [$id]) === 1;
    }

    public function deleteFailed(int $id): bool
    {
        return $this->connection->execute("DELETE FROM {$this->table} WHERE id = ? AND status = 'failed'", [$id]) === 1;
    }

    public function stats(?string $queue = null): TaskStats
    {
        if ($queue === '') {
            throw new InvalidArgumentException('Stats queue name cannot be empty');
        }
        if ($queue !== null) {
            StoreValues::assertNoNul($queue, 'The queue name');
        }
        $filter = $queue === null ? '' : ' AND queue = ?';
        $row = $this->connection->fetchAll(
            "SELECT
            (SELECT COUNT(*) FROM {$this->table} WHERE status = 'pending' AND available_at <= now(){$filter}) AS ready,
            (SELECT COUNT(*) FROM {$this->table} WHERE status = 'pending' AND available_at > now(){$filter}) AS scheduled,
            (SELECT COUNT(*) FROM {$this->table} WHERE status = 'running'{$filter}) AS running,
            (SELECT COUNT(*) FROM {$this->table} WHERE status = 'failed'{$filter}) AS failed,
            (SELECT FLOOR(EXTRACT(EPOCH FROM MIN(available_at)))::BIGINT FROM {$this->table} WHERE status = 'pending'{$filter}) AS earliest_pending_at",
            $queue === null ? [] : array_fill(0, 5, $queue),
        )[0] ?? throw new UnexpectedValueException('Stats query returned no row');

        return StoreValues::stats($row);
    }

    public function purge(int $olderThanSeconds, ?int $failedOlderThanSeconds = null): int
    {
        StoreValues::assertDuration($olderThanSeconds, 'The done retention');
        if ($failedOlderThanSeconds !== null) {
            StoreValues::assertDuration($failedOlderThanSeconds, 'The failed retention');
        }
        $deleted = $this->connection->execute("DELETE FROM {$this->table}
            WHERE status = 'done' AND finished_at < now() - CAST(? AS INTEGER) * INTERVAL '1 second'", [$olderThanSeconds]);
        if ($failedOlderThanSeconds !== null) {
            $deleted += $this->connection->execute("DELETE FROM {$this->table}
                WHERE status = 'failed' AND finished_at < now() - CAST(? AS INTEGER) * INTERVAL '1 second'", [$failedOlderThanSeconds]);
        }
        return $deleted;
    }
}
