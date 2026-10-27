<?php

declare(strict_types=1);

namespace Kaly\Tasks\Store;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kaly\Tasks\Connection\Connection;
use Kaly\Tasks\Connection\TransactionalConnection;
use Kaly\Tasks\EncodedTask;
use PDOException;
use Throwable;
use UnexpectedValueException;

/**
 * MySQL 8 task store, also suitable for Aurora MySQL v3 on its writer endpoint.
 * Reservations use a short transaction; payload text is preserved verbatim.
 */
final readonly class MysqlTaskStore implements TaskStore
{
    public function __construct(
        private TransactionalConnection $connection,
        private string $table = 'kaly_tasks',
    ) {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $table) !== 1 || strlen($table) > 64) {
            throw new InvalidArgumentException('Table name must be a lowercase identifier of at most 64 characters');
        }
    }

    /**
     * The DDL to create the table, for your migrations.
     *
     * @return list<string>
     */
    public function schema(): array
    {
        $t = '`' . $this->table . '`';
        return [
            "CREATE TABLE {$t} (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                queue VARBINARY(255) NOT NULL,
                name LONGTEXT NOT NULL,
                payload LONGTEXT NOT NULL,
                dedup_key LONGTEXT,
                dedup_hash BINARY(32),
                status VARCHAR(7) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'running', 'done', 'failed')),
                attempts INTEGER NOT NULL DEFAULT 0,
                available_at DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
                owner VARBINARY(255),
                lease_until DATETIME(6),
                last_error TEXT,
                created_at DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
                finished_at DATETIME(6),
                INDEX pending (status, queue, available_at, id),
                INDEX running (status, lease_until),
                INDEX finished (status, finished_at),
                UNIQUE INDEX dedup (dedup_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin",
        ];
    }

    public function push(EncodedTask $task, ?DateTimeImmutable $notBefore = null): bool
    {
        StoreValues::assertNoNul($task->name, 'The task name');
        StoreValues::assertNoNul($task->queue, 'The queue name');
        if ($task->deduplicationKey !== null) {
            StoreValues::assertNoNul($task->deduplicationKey, 'The deduplication key');
        }
        // Length-prefix the name to make the pair unambiguous, with bytewise keys
        $hash = $task->deduplicationKey === null ? null : hash('sha256', strlen($task->name) . ':' . $task->name . $task->deduplicationKey);
        try {
            return $this->connection->execute(
                "INSERT INTO `{$this->table}` (queue, name, payload, dedup_key, dedup_hash, available_at) VALUES (?, ?, ?, ?, UNHEX(?), COALESCE(?, UTC_TIMESTAMP(6)))",
                [
                    $task->queue,
                    $task->name,
                    $task->payload,
                    $task->deduplicationKey,
                    $hash,
                    $notBefore?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                ],
            ) === 1;
        } catch (Throwable $error) {
            // Only duplicate keys are skipped, never truncation or invalid data
            for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof PDOException && ($cause->errorInfo[1] ?? null) === 1062) {
                    return false;
                }
            }
            throw $error;
        }
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
        // One ordered index range per queue avoids filesort locking every due row.
        // Vary the first queue so a busy queue does not always take precedence.
        shuffle($queues);
        return $this->connection->transaction(function (Connection $connection) use ($queues, $owner, $leaseSeconds): ?ReservedTask {
            foreach ($queues as $queue) {
                $row = $connection->fetchAll("SELECT id, name, payload, attempts FROM `{$this->table}` FORCE INDEX (pending)
                    WHERE status = 'pending' AND queue = ? AND available_at <= UTC_TIMESTAMP(6)
                    ORDER BY available_at, id LIMIT 1 FOR UPDATE SKIP LOCKED", [$queue])[0] ?? null;
                if ($row === null) {
                    continue;
                }
                $task = StoreValues::reserved($row, $owner);
                $connection->execute(
                    "UPDATE `{$this->table}`
                    SET status = 'running', owner = ?, attempts = attempts + 1,
                        lease_until = UTC_TIMESTAMP(6) + INTERVAL ? SECOND
                    WHERE id = ?",
                    [$owner, $leaseSeconds, $task->id],
                );
                return new ReservedTask($task->id, $task->name, $task->payload, $task->attempts + 1, $owner);
            }
            return null;
        });
    }

    public function complete(ReservedTask $task): bool
    {
        StoreValues::assertNoNul($task->owner, 'The owner token');
        return $this->connection->execute(
            "UPDATE `{$this->table}`
            SET status = 'done', owner = NULL, lease_until = NULL, last_error = NULL, finished_at = UTC_TIMESTAMP(6)
            WHERE id = ? AND owner = ? AND status = 'running'",
            [$task->id, $task->owner],
        ) === 1;
    }

    public function release(ReservedTask $task, int $delaySeconds, string $error): bool
    {
        StoreValues::assertNoNul($task->owner, 'The owner token');
        StoreValues::assertDuration($delaySeconds, 'The retry delay');
        return $this->connection->execute(
            "UPDATE `{$this->table}`
            SET status = 'pending', owner = NULL, lease_until = NULL, last_error = ?,
                available_at = UTC_TIMESTAMP(6) + INTERVAL ? SECOND
            WHERE id = ? AND owner = ? AND status = 'running'",
            [StoreValues::error($error), $delaySeconds, $task->id, $task->owner],
        ) === 1;
    }

    public function fail(ReservedTask $task, string $error): bool
    {
        StoreValues::assertNoNul($task->owner, 'The owner token');
        return $this->connection->execute(
            "UPDATE `{$this->table}`
            SET status = 'failed', owner = NULL, lease_until = NULL, last_error = ?, finished_at = UTC_TIMESTAMP(6)
            WHERE id = ? AND owner = ? AND status = 'running'",
            [StoreValues::error($error), $task->id, $task->owner],
        ) === 1;
    }

    public function recover(): int
    {
        return $this->connection->execute("UPDATE `{$this->table}`
            SET status = 'pending', owner = NULL, lease_until = NULL, last_error = 'Lease expired'
            WHERE status = 'running' AND lease_until < UTC_TIMESTAMP(6)");
    }

    public function failed(int $limit = 50): array
    {
        StoreValues::assertDuration($limit, 'The limit', 1);
        $rows = $this->connection->fetchAll("SELECT id, queue, name, payload, attempts, last_error,
                TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', created_at) AS created_at,
                TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', finished_at) AS finished_at FROM `{$this->table}` AS t
            WHERE status = 'failed' ORDER BY t.finished_at DESC, id DESC LIMIT ?", [$limit]);
        return array_map(StoreValues::failed(...), $rows);
    }

    public function retryFailed(int $id): bool
    {
        return $this->connection->execute("UPDATE `{$this->table}`
            SET status = 'pending', attempts = 0, available_at = UTC_TIMESTAMP(6), finished_at = NULL
            WHERE id = ? AND status = 'failed'", [$id]) === 1;
    }

    public function deleteFailed(int $id): bool
    {
        return $this->connection->execute("DELETE FROM `{$this->table}` WHERE id = ? AND status = 'failed'", [$id]) === 1;
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
            (SELECT COUNT(*) FROM `{$this->table}` WHERE status = 'pending' AND available_at <= UTC_TIMESTAMP(6){$filter}) AS ready,
            (SELECT COUNT(*) FROM `{$this->table}` WHERE status = 'pending' AND available_at > UTC_TIMESTAMP(6){$filter}) AS scheduled,
            (SELECT COUNT(*) FROM `{$this->table}` WHERE status = 'running'{$filter}) AS running,
            (SELECT COUNT(*) FROM `{$this->table}` WHERE status = 'failed'{$filter}) AS failed,
            (SELECT FLOOR(TIMESTAMPDIFF(MICROSECOND, '1970-01-01 00:00:00', MIN(available_at)) / 1000000) FROM `{$this->table}` WHERE status = 'pending'{$filter}) AS earliest_pending_at",
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
        $deleted = $this->connection->execute("DELETE FROM `{$this->table}`
            WHERE status = 'done' AND finished_at < UTC_TIMESTAMP(6) - INTERVAL ? SECOND", [$olderThanSeconds]);
        if ($failedOlderThanSeconds !== null) {
            $deleted += $this->connection->execute("DELETE FROM `{$this->table}`
                WHERE status = 'failed' AND finished_at < UTC_TIMESTAMP(6) - INTERVAL ? SECOND", [$failedOlderThanSeconds]);
        }
        return $deleted;
    }
}
