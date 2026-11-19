<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use DateTimeImmutable;
use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\DatabaseTaskDispatcher;
use Kaly\Tasks\EncodedTask;
use Kaly\Tasks\Store\PostgresNotifyWaiter;
use Kaly\Tasks\Store\PostgresTaskStore;
use Kaly\Tasks\Store\ReservedTask;
use Kaly\Tasks\Store\TaskStats;
use Kaly\Tasks\Store\TaskStore;
use Kaly\Tasks\TaskRegistry;
use Kaly\Tasks\Tests\Engine\OnPostgres;
use Kaly\Tasks\Tests\Fixtures\Journal;
use Kaly\Tasks\Tests\Fixtures\RecordTask;
use Kaly\Tasks\Worker;
use Kaly\Tasks\WorkerOptions;
use PDO;
use PDOException;
use ReflectionMethod;
use RuntimeException;

/**
 * Counts reservation calls, to bound the cost of a burst of notifications.
 */
final class CountingTaskStore implements TaskStore
{
    public int $reserves = 0;

    public function __construct(
        private readonly TaskStore $store,
    ) {}

    public function schema(): array
    {
        return $this->store->schema();
    }

    public function push(EncodedTask $task, ?DateTimeImmutable $notBefore = null): bool
    {
        return $this->store->push($task, $notBefore);
    }

    public function reserve(array $queues, string $owner, int $leaseSeconds): ?ReservedTask
    {
        $this->reserves++;
        return $this->store->reserve($queues, $owner, $leaseSeconds);
    }

    public function complete(ReservedTask $task): bool
    {
        return $this->store->complete($task);
    }

    public function release(ReservedTask $task, int $delaySeconds, string $error): bool
    {
        return $this->store->release($task, $delaySeconds, $error);
    }

    public function fail(ReservedTask $task, string $error): bool
    {
        return $this->store->fail($task, $error);
    }

    public function recover(): int
    {
        return $this->store->recover();
    }

    /**
     * @return list<array{id:int,queue:string,name:string,payload:string,attempts:int,error:string|null,created_at:int,finished_at:int}>
     */
    public function failed(int $limit = 50): array
    {
        return $this->store->failed($limit);
    }

    public function retryFailed(int $id): bool
    {
        return $this->store->retryFailed($id);
    }

    public function deleteFailed(int $id): bool
    {
        return $this->store->deleteFailed($id);
    }

    public function stats(?string $queue = null): TaskStats
    {
        return $this->store->stats($queue);
    }

    public function purge(int $olderThanSeconds, ?int $failedOlderThanSeconds = null): int
    {
        return $this->store->purge($olderThanSeconds, $failedOlderThanSeconds);
    }
}

final class PostgresNotifyTest extends StoreTestCase
{
    use OnPostgres;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ($this->postgresStore()->notificationSchema() as $statement) {
            $this->pdo()->exec($statement);
        }
    }

    private function postgresStore(): PostgresTaskStore
    {
        $store = $this->store();
        $this->assertInstanceOf(PostgresTaskStore::class, $store);
        return $store;
    }

    private function waiter(int $fallbackMs = 5000, int $sliceMs = 250): PostgresNotifyWaiter
    {
        return PostgresNotifyWaiter::fromDsn($this->postgresStore(), $this->postgres()->dsn, fallbackMs: $fallbackMs, sliceMs: $sliceMs);
    }

    public function testTheTriggerNotifiesOnlyOnCommit(): void
    {
        $waiter = $this->waiter();
        $waiter->start();
        try {
            $this->pdo()->beginTransaction();
            $this->store()->push(new EncodedTask('test.record', 'default', '{}'));
            $this->assertFalse($waiter->wait(['default'], 100), 'An open transaction must not notify');

            $this->pdo()->commit();
            $this->assertTrue($waiter->wait(['default'], 1000), 'A committed task must notify');
        } finally {
            $waiter->close();
        }
    }

    public function testARollbackDoesNotNotify(): void
    {
        $waiter = $this->waiter();
        $waiter->start();
        try {
            $this->pdo()->beginTransaction();
            $this->store()->push(new EncodedTask('test.record', 'default', '{}'));
            $this->pdo()->rollBack();

            $this->assertFalse($waiter->wait(['default'], 100));
        } finally {
            $waiter->close();
        }
    }

    public function testDeduplicationDoesNotNotify(): void
    {
        $waiter = $this->waiter();
        $waiter->start();
        try {
            $task = new EncodedTask('test.record', 'default', '{}', 'dedup');
            $this->assertTrue($this->store()->push($task));
            $this->assertTrue($waiter->wait(['default'], 1000));

            $this->assertFalse($this->store()->push($task));
            $this->assertFalse($waiter->wait(['default'], 100));
        } finally {
            $waiter->close();
        }
    }

    public function testReleaseNotifiesTheTaskAgain(): void
    {
        $waiter = $this->waiter();
        $waiter->start();
        try {
            $this->store()->push(new EncodedTask('test.record', 'default', '{}'));
            $this->assertTrue($waiter->wait(['default'], 1000));

            $reserved = $this->store()->reserve(['default'], 'owner', 60);
            $this->assertNotNull($reserved);
            $this->assertTrue($this->store()->release($reserved, 0, 'retry'));

            $this->assertTrue($waiter->wait(['default'], 1000));
        } finally {
            $waiter->close();
        }
    }

    public function testRecoveryNotifiesTheTaskAgain(): void
    {
        $waiter = $this->waiter();
        $waiter->start();
        try {
            $this->store()->push(new EncodedTask('test.record', 'default', '{}'));
            $this->assertTrue($waiter->wait(['default'], 1000));
            $this->assertNotNull($this->store()->reserve(['default'], 'owner', 60));
            $this->age(61);

            $this->assertSame(1, $this->store()->recover());
            $this->assertTrue($waiter->wait(['default'], 1000));
        } finally {
            $waiter->close();
        }
    }

    public function testADeferredRetryNotifiesThenWaitsForItsDelay(): void
    {
        $waiter = $this->waiter();
        $waiter->start();
        try {
            $this->store()->push(new EncodedTask('test.record', 'default', '{}'));
            $this->assertTrue($waiter->wait(['default'], 1000));

            $reserved = $this->store()->reserve(['default'], 'owner', 60);
            $this->assertNotNull($reserved);
            $this->assertTrue($this->store()->release($reserved, 1, 'retry'));

            // The transition back to pending wakes the worker
            $this->assertTrue($waiter->wait(['default'], 1000));
            $started = microtime(true);
            $this->assertFalse($waiter->wait(['default'], 3000));
            $this->assertGreaterThan(0.7, microtime(true) - $started, 'The retry must not be reserved before its delay');
        } finally {
            $waiter->close();
        }
    }

    public function testASequenceContinuationNotifies(): void
    {
        $waiter = $this->waiter();
        $waiter->start();
        try {
            $this->store()->push(new EncodedTask('test.record', 'default', '{}', chain: [
                new EncodedTask('test.record', 'default', '{}'),
            ]));
            $this->assertTrue($waiter->wait(['default'], 1000));

            $first = $this->store()->reserve(['default'], 'owner', 60);
            $this->assertNotNull($first);
            $this->assertTrue($this->store()->complete($first));

            $this->assertTrue($waiter->wait(['default'], 1000), 'Completing a step inserts its successor');
            $this->assertNotNull($this->store()->reserve(['default'], 'owner', 60));
        } finally {
            $waiter->close();
        }
    }

    public function testCompletionDoesNotNotify(): void
    {
        $waiter = $this->waiter();
        $waiter->start();
        try {
            $this->store()->push(new EncodedTask('test.record', 'default', '{}'));
            $this->assertTrue($waiter->wait(['default'], 1000));
            $reserved = $this->store()->reserve(['default'], 'owner', 60);
            $this->assertNotNull($reserved);
            $this->assertTrue($this->store()->complete($reserved));

            $this->assertFalse($waiter->wait(['default'], 100));
        } finally {
            $waiter->close();
        }
    }

    public function testMovingAPendingTaskForwardNotifies(): void
    {
        $waiter = $this->waiter();
        $waiter->start();
        try {
            $this->store()->push(new EncodedTask('test.record', 'default', '{}'), new DateTimeImmutable('+1 hour'));
            $this->assertTrue($waiter->wait(['default'], 1000));

            $this->pdo()->exec('UPDATE ' . self::TABLE . ' SET available_at = now()');
            $this->assertTrue($waiter->wait(['default'], 1000));
        } finally {
            $waiter->close();
        }
    }

    public function testMovingAPendingTaskToAnotherQueueNotifies(): void
    {
        $waiter = $this->waiter();
        $waiter->start();
        try {
            $this->store()->push(new EncodedTask('test.record', 'default', '{}'));
            $this->assertTrue($waiter->wait(['default'], 1000));

            $this->pdo()->exec('UPDATE ' . self::TABLE . " SET queue = 'other'");
            $this->assertTrue($waiter->wait(['other'], 1000));
        } finally {
            $waiter->close();
        }
    }

    public function testNotificationSchemaIsIdempotentAndCanBeDropped(): void
    {
        $store = $this->postgresStore();
        foreach ($store->notificationSchema() as $statement) {
            $this->pdo()->exec($statement);
        }
        $this->assertTrue($store->notificationsEnabled());

        foreach ($store->dropNotificationSchema() as $statement) {
            $this->pdo()->exec($statement);
        }
        $this->assertFalse($store->notificationsEnabled());

        $waiter = $this->waiter();
        $waiter->start();
        try {
            $this->store()->push(new EncodedTask('test.record', 'default', '{}'));
            $this->assertFalse($waiter->wait(['default'], 100), 'A dropped trigger falls back to polling');
        } finally {
            $waiter->close();
        }
    }

    public function testNextDueInMsFiltersQueuesAndUsesTheSqlClock(): void
    {
        $store = $this->postgresStore();
        $this->assertNull($store->nextDueInMs(['empty']));

        $store->push(new EncodedTask('test.record', 'default', '{}'), new DateTimeImmutable('+2 seconds'));

        $due = $store->nextDueInMs(['default']);
        $this->assertNotNull($due);
        $this->assertGreaterThan(1000, $due);
        $this->assertLessThan(3000, $due);
        $this->assertNull($store->nextDueInMs(['other']));
    }

    public function testNextDueInMsUsesTheEarliestOfSeveralQueues(): void
    {
        $store = $this->postgresStore();
        $store->push(new EncodedTask('test.record', 'default', '{}'), new DateTimeImmutable('+5 seconds'));
        $store->push(new EncodedTask('test.record', 'other', '{}'), new DateTimeImmutable('+1 second'));

        $due = $store->nextDueInMs(['default', 'other']);
        $this->assertNotNull($due);
        $this->assertGreaterThan(500, $due);
        $this->assertLessThan(3000, $due);
    }

    public function testNextDueInMsIsIndependentOfTheSessionTimezone(): void
    {
        $this->pdo()->exec("SET TIME ZONE '+05:30'");
        try {
            $this->store()->push(new EncodedTask('test.record', 'default', '{}'), new DateTimeImmutable('+2 seconds'));

            $due = $this->postgresStore()->nextDueInMs(['default']);
            $this->assertNotNull($due);
            $this->assertGreaterThan(1000, $due);
            $this->assertLessThan(3000, $due);
        } finally {
            $this->pdo()->exec("SET TIME ZONE 'UTC'");
        }
    }

    public function testTheChannelIsDerivedFromTheTable(): void
    {
        $store = $this->postgresStore();
        $channel = $store->notificationChannel();
        $this->assertStringStartsWith('kaly_tasks_', $channel);
        $this->assertStringEndsWith('_ready', $channel);
        $this->assertLessThanOrEqual(63, strlen($channel));
        $this->assertSame($channel, $store->notificationChannel(), 'The channel must be stable');

        $connection = new PdoConnection($this->pdo());
        // A schema separator and a table name must not collide
        $schemaSeparator = new PostgresTaskStore($connection, 'jobs.fast_queue');
        $tableSeparator = new PostgresTaskStore($connection, 'jobs_fast.queue');
        $this->assertNotSame($schemaSeparator->notificationChannel(), $tableSeparator->notificationChannel());

        $long = new PostgresTaskStore($connection, str_repeat('a', 55));
        $this->assertLessThanOrEqual(63, strlen($long->notificationChannel()));
        $this->assertNotSame($channel, $long->notificationChannel());
    }

    public function testTheWaiterDoesNotMissANotificationArrivingBeforeTheWait(): void
    {
        $waiter = $this->waiter();
        $waiter->start();
        try {
            // The worker just found nothing
            $this->assertNull($this->store()->reserve(['default'], 'owner', 60));
            // A producer commits before the wait starts. The task is deferred,
            // so the due bound cannot mask a missed notification.
            $this->store()->push(new EncodedTask('test.record', 'default', '{}'), new DateTimeImmutable('+1 hour'));

            $started = microtime(true);
            $this->assertTrue($waiter->wait(['default'], 2000));
            $this->assertLessThan(0.2, microtime(true) - $started);
        } finally {
            $waiter->close();
        }
    }

    public function testTheWaiterDrainsQueuedNotificationsInOneWait(): void
    {
        $waiter = $this->waiter();
        $waiter->start();
        try {
            for ($i = 0; $i < 5; $i++) {
                $this->store()->push(new EncodedTask('test.record', 'default', '{}'));
            }

            $this->assertTrue($waiter->wait(['default'], 2000));
            // Without draining, each queued notification would wake the worker again
            $this->assertFalse($waiter->wait(['default'], 500));
        } finally {
            $waiter->close();
        }
    }

    public function testTheWaiterWakesAtTheNextAvailableAt(): void
    {
        $waiter = $this->waiter();
        $waiter->start();
        try {
            $this->store()->push(new EncodedTask('test.record', 'default', '{}'), new DateTimeImmutable('+1 second'));
            // The insertion itself wakes the worker; the deferred task is not due yet
            $this->assertTrue($waiter->wait(['default'], 2000));

            $started = microtime(true);
            $this->assertFalse($waiter->wait(['default'], 5000));
            $elapsed = microtime(true) - $started;
            $this->assertGreaterThan(0.8, $elapsed);
            $this->assertLessThan(2.0, $elapsed);
        } finally {
            $waiter->close();
        }
    }

    public function testSigtermInterruptsAnIdleWait(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('Requires ext-pcntl');
        }
        [$process, $pipes] = $this->startProcess('notify-worker.php', ['30', '5000']);
        try {
            // Let the worker reach its idle wait, with a five second fallback
            usleep(1_000_000);
            $started = microtime(true);
            proc_terminate($process, 15);
            $errors = (string) stream_get_contents($pipes[2]);
            $exit = proc_close($process);
            $elapsed = microtime(true) - $started;

            $this->assertSame(0, $exit, $errors);
            $this->assertLessThan(2.0, $elapsed, 'SIGTERM must interrupt the wait instead of waiting for the fallback');
        } finally {
            if (is_resource($process)) {
                proc_terminate($process, 9);
                proc_close($process);
            }
        }
    }

    public function testStopBeforeWaitDoesNotOpenTheListener(): void
    {
        // A DSN that cannot connect: reaching the listener would throw
        $waiter = PostgresNotifyWaiter::fromDsn($this->postgresStore(), 'pgsql:host=127.0.0.1;port=1;dbname=missing;connect_timeout=1');
        $waiter->stop();
        try {
            $this->assertFalse($waiter->wait(['default'], 1000));
        } finally {
            $waiter->close();
        }
    }

    public function testALostListenerFallsBackToPollingWhenReconnectFails(): void
    {
        $listener = $this->listenerConnection();
        $pidResult = $listener->query('SELECT pg_backend_pid()');
        if ($pidResult === false) {
            throw new RuntimeException('Could not read the listener backend id');
        }
        $pid = (int) $pidResult->fetchColumn();
        $attempts = 0;
        $connection = static function () use ($listener, &$attempts): PDO {
            $attempts++;
            if ($attempts === 1) {
                return $listener;
            }
            throw new PDOException('simulated reconnect failure');
        };
        $waiter = new PostgresNotifyWaiter($this->postgresStore(), $connection, fallbackMs: 100, sliceMs: 50);
        $waiter->start();
        try {
            // Cut the LISTEN session, keeping the store connection alive
            $result = $this->pdo()->query('SELECT pg_terminate_backend(' . $pid . ')');
            if ($result === false) {
                throw new RuntimeException('Could not terminate the listener backend');
            }
            $this->assertTrue((bool) $result->fetchColumn());

            // The loss forces one poll, then the next wait retries the listener
            $this->assertTrue($waiter->wait(['default'], 500));

            $started = microtime(true);
            $this->assertFalse($waiter->wait(['default'], 300), 'A failed reconnect must fall back, not throw');
            $elapsed = microtime(true) - $started;
            $this->assertSame(2, $attempts);
            $this->assertGreaterThan(0.05, $elapsed);
            $this->assertLessThan(1.0, $elapsed);
        } finally {
            $waiter->close();
        }
    }

    /**
     * `new PDO()` returns a generic handle on PHP 8.5, where only
     * `Pdo\Pgsql::getNotify()` is supported. Mirror `fromDsn()`.
     */
    private function listenerConnection(): PDO
    {
        if (PHP_VERSION_ID >= 80_400) {
            $connect = new ReflectionMethod(PDO::class, 'connect');
            $pdo = $connect->invoke(null, $this->postgres()->dsn);
            if (!$pdo instanceof PDO) {
                throw new RuntimeException('PDO::connect() did not return a connection');
            }
            return $pdo;
        }
        return new PDO($this->postgres()->dsn);
    }

    public function testABurstDoesNotCauseOneReservationPerNotification(): void
    {
        $store = $this->postgresStore();
        $waiter = $this->waiter();
        // Subscribe before the burst: every commit leaves a queued notification
        $waiter->start();
        $registry = (new TaskRegistry())->register('test.record', RecordTask::class);
        $tasks = new DatabaseTaskDispatcher($store, $registry);
        $count = 10;
        for ($i = 0; $i < $count; $i++) {
            $tasks->dispatch(new RecordTask('entry-' . $i));
        }

        $counting = new CountingTaskStore($store);
        $journal = new Journal();
        $worker = new Worker($counting, $registry, static fn(object $task) => $task($journal), waiter: $waiter);

        try {
            $this->assertSame($count, $worker->run(new WorkerOptions(timeout: 0, maxTasks: $count + 100, maxRuntime: 2, sleepMs: 0)));
            // Without draining, the queued notifications cost about one extra
            // reservation each: this bound keeps that regression visible.
            $this->assertLessThanOrEqual($count + 5, $counting->reserves);
        } finally {
            $waiter->close();
        }
    }
}
