<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Di\Injector;
use Kaly\Tasks\DatabaseTaskDispatcher;
use Kaly\Tasks\EncodedTask;
use Kaly\Tasks\RetryPolicy;
use Kaly\Tasks\TaskRegistry;
use Kaly\Tasks\Tests\Fixtures\Journal;
use Kaly\Tasks\Tests\Fixtures\RecordTask;
use Kaly\Tasks\Worker;
use Kaly\Tasks\WorkerOptions;
use PDO;
use Psr\Log\AbstractLogger;
use Stringable;

final class TestLogger extends AbstractLogger
{
    /**
     * @var list<string>
     */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = (is_string($level) ? $level : '') . ' ' . $message;
    }
}

final readonly class StealLeaseTask
{
    public function __construct(
        public string $table,
    ) {}

    public function __invoke(PDO $pdo): void
    {
        $pdo->exec("UPDATE {$this->table} SET owner = 'thief'");
    }
}

final readonly class StopWorkerTask
{
    public function __construct() {}

    public function __invoke(Worker $worker): void
    {
        $worker->stop();
    }
}

class WorkerTest extends PostgresTestCase
{
    private Journal $journal;

    private TestLogger $logger;

    private TaskRegistry $registry;

    private Worker $worker;

    private DatabaseTaskDispatcher $tasks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->journal = new Journal();
        $this->logger = new TestLogger();
        $this->registry = (new TaskRegistry())
            ->register('test.record', RecordTask::class, retry: new RetryPolicy(maxAttempts: 2, delay: 0))
            ->register('test.steal', StealLeaseTask::class)
            ->register('test.stop', StopWorkerTask::class);
        $container = new Container(
            Definitions::create()
                ->set(Journal::class, $this->journal)
                ->set(PDO::class, $this->pdo)
                // Lets a task stop the running worker
                ->set(Worker::class, fn(): Worker => $this->worker),
        );
        $this->worker = new Worker($this->store, $this->registry, new Injector($container), $this->logger);
        $this->tasks = new DatabaseTaskDispatcher($this->store, $this->registry);
    }

    private function work(int $maxTasks = 1000): int
    {
        return $this->worker->run(new WorkerOptions(timeout: 0, maxTasks: $maxTasks, sleepMs: 0, stopWhenEmpty: true));
    }

    public function testRunsTasksWithTheirServices(): void
    {
        $this->tasks->dispatch(new RecordTask('first'));
        $this->tasks->dispatch(new RecordTask('second'));

        $this->assertSame(2, $this->work());
        $this->assertSame(['first', 'second'], $this->journal->entries);
        $this->assertSame(2, $this->countTasks('done'));
    }

    public function testRetriesThenFails(): void
    {
        $this->tasks->dispatch(new RecordTask('fail-me'));

        $this->assertSame(2, $this->work());

        $row = $this->row(1);
        $this->assertSame(['failed', 2, 'RuntimeException: Cannot record fail-me'], [$row['status'], $row['attempts'], $row['last_error']]);
        $this->assertStringStartsWith('warning Task {task} #{id} will retry', $this->logger->records[0]);
        $this->assertStringStartsWith('error Task {task} #{id} failed', $this->logger->records[1]);
    }

    public function testUndecodableTasksFailWithoutRetry(): void
    {
        $this->store->push(new EncodedTask('test.record', 'default', '{"bogus":1}'));
        $this->store->push(new EncodedTask('test.unknown', 'default', '{}'));

        $this->assertSame(2, $this->work());

        $this->assertSame(2, $this->countTasks('failed'));
        $this->assertSame("Task 'test.unknown' is not registered", $this->row(2)['last_error']);
    }

    public function testTasksAbandonedTooOftenFailWithoutRunning(): void
    {
        $this->tasks->dispatch(new RecordTask('crash'));
        // Two workers died with the task: leases expired and were recovered
        foreach (['dead-1', 'dead-2'] as $owner) {
            $this->store->reserve(['default'], $owner, 60);
            $this->age(61);
            $this->store->recover();
        }

        $this->assertSame(1, $this->work());

        $this->assertSame([], $this->journal->entries);
        $this->assertSame(['failed', 'Exceeded 2 attempts'], [$this->row(1)['status'], $this->row(1)['last_error']]);
    }

    public function testRecoversExpiredLeasesOnStart(): void
    {
        $this->tasks->dispatch(new RecordTask('crash'));
        $this->store->reserve(['default'], 'dead', 60);
        $this->age(61);

        $this->assertSame(1, $this->work());

        $this->assertSame(['crash'], $this->journal->entries);
        $this->assertStringStartsWith('warning Recovered {count} tasks', $this->logger->records[0]);
    }

    public function testWarnsWhenCompletingALostLease(): void
    {
        $this->tasks->dispatch(new StealLeaseTask(self::TABLE));

        $this->work();

        $this->assertSame('running', $this->row(1)['status']);
        $this->assertSame(['warning Task {task} #{id} completed after losing its lease and may run twice'], $this->logger->records);
    }

    public function testStopsAfterMaxTasks(): void
    {
        foreach (['a', 'b', 'c'] as $entry) {
            $this->tasks->dispatch(new RecordTask($entry));
        }

        $this->assertSame(2, $this->work(maxTasks: 2));
        $this->assertSame(1, $this->countTasks('pending'));
    }

    public function testStopFinishesTheCurrentTask(): void
    {
        $this->tasks->dispatch(new StopWorkerTask());
        $this->tasks->dispatch(new RecordTask('after'));

        $this->assertSame(1, $this->work());
        $this->assertSame([1, 1], [$this->countTasks('done'), $this->countTasks('pending')]);
    }
}
