<?php

declare(strict_types=1);

namespace Kaly\Tasks\Store;

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

    public function push(EncodedTask $task): bool
    {
        return $this->connection->execute(
            "INSERT INTO {$this->table} (queue, name, payload, dedup_key) VALUES (?, ?, CAST(? AS JSON), ?)
            ON CONFLICT (name, dedup_key) WHERE dedup_key IS NOT NULL DO NOTHING",
            [$task->queue, $task->name, $task->payload, $task->deduplicationKey],
        ) === 1;
    }

    public function reserve(array $queues, string $owner, int $leaseSeconds): ?ReservedTask
    {
        if ($queues === []) {
            throw new InvalidArgumentException('Reserve needs at least one queue');
        }
        self::assertDuration($leaseSeconds, 'The lease', 1);
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

        if ($row === null) {
            return null;
        }
        return new ReservedTask(
            self::int($row['id']),
            self::string($row['name']),
            self::string($row['payload']),
            self::int($row['attempts']),
            $owner,
        );
    }

    public function complete(ReservedTask $task): bool
    {
        return $this->connection->execute(
            "UPDATE {$this->table}
            SET status = 'done', owner = NULL, lease_until = NULL, last_error = NULL, finished_at = now()
            WHERE id = ? AND owner = ? AND status = 'running'",
            [$task->id, $task->owner],
        ) === 1;
    }

    public function release(ReservedTask $task, int $delaySeconds, string $error): bool
    {
        self::assertDuration($delaySeconds, 'The retry delay');
        return $this->connection->execute(
            "UPDATE {$this->table}
            SET status = 'pending', owner = NULL, lease_until = NULL, last_error = ?,
                available_at = now() + CAST(? AS INTEGER) * INTERVAL '1 second'
            WHERE id = ? AND owner = ? AND status = 'running'",
            [$error, $delaySeconds, $task->id, $task->owner],
        ) === 1;
    }

    public function fail(ReservedTask $task, string $error): bool
    {
        return $this->connection->execute(
            "UPDATE {$this->table}
            SET status = 'failed', owner = NULL, lease_until = NULL, last_error = ?, finished_at = now()
            WHERE id = ? AND owner = ? AND status = 'running'",
            [$error, $task->id, $task->owner],
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
        self::assertDuration($limit, 'The limit', 1);
        $rows = $this->connection->fetchAll(
            "SELECT id, queue, name, CAST(payload AS TEXT) AS payload, attempts, last_error FROM {$this->table}
            WHERE status = 'failed' ORDER BY finished_at DESC, id DESC LIMIT CAST(? AS INTEGER)",
            [$limit],
        );
        return array_map(static fn(array $row): array => [
            'id' => self::int($row['id']),
            'queue' => self::string($row['queue']),
            'name' => self::string($row['name']),
            'payload' => self::string($row['payload']),
            'attempts' => self::int($row['attempts']),
            'error' => $row['last_error'] === null ? null : self::string($row['last_error']),
        ], $rows);
    }

    public function retryFailed(int $id): bool
    {
        return $this->connection->execute("UPDATE {$this->table}
            SET status = 'pending', attempts = 0, available_at = now(), finished_at = NULL
            WHERE id = ? AND status = 'failed'", [$id]) === 1;
    }

    public function purge(int $olderThanSeconds, ?int $failedOlderThanSeconds = null): int
    {
        self::assertDuration($olderThanSeconds, 'The done retention');
        if ($failedOlderThanSeconds !== null) {
            self::assertDuration($failedOlderThanSeconds, 'The failed retention');
        }
        $deleted = $this->connection->execute("DELETE FROM {$this->table}
            WHERE status = 'done' AND finished_at < now() - CAST(? AS INTEGER) * INTERVAL '1 second'", [$olderThanSeconds]);
        if ($failedOlderThanSeconds !== null) {
            $deleted += $this->connection->execute("DELETE FROM {$this->table}
                WHERE status = 'failed' AND finished_at < now() - CAST(? AS INTEGER) * INTERVAL '1 second'", [$failedOlderThanSeconds]);
        }
        return $deleted;
    }

    /**
     * Durations and limits are bound as SQL INTEGER.
     */
    private static function assertDuration(int $value, string $what, int $min = 0): void
    {
        if ($value < $min || $value > 2_147_483_647) {
            throw new InvalidArgumentException("{$what} must be between {$min} and 2147483647, got {$value}");
        }
    }

    /**
     * Drivers return integers as int or numeric string.
     */
    private static function int(mixed $value): int
    {
        if (is_int($value) || is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        throw new UnexpectedValueException('Expected an integer column, got ' . get_debug_type($value));
    }

    private static function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new UnexpectedValueException('Expected a text column, got ' . get_debug_type($value));
        }
        return $value;
    }
}
