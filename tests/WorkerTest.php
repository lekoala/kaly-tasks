<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Closure;
use InvalidArgumentException;
use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Di\Injector;
use Kaly\Tasks\PermanentFailure;
use Kaly\Tasks\RetryLater;
use Kaly\Tasks\RetryPolicy;
use Kaly\Tasks\Store\ReservedTask;
use Kaly\Tasks\Store\TaskStore;
use Kaly\Tasks\TaskRegistry;
use Kaly\Tasks\TaskRun;
use Kaly\Tasks\Tests\Fixtures\FailingTask;
use Kaly\Tasks\Tests\Fixtures\Journal;
use Kaly\Tasks\Tests\Fixtures\RecordTask;
use Kaly\Tasks\Tests\Fixtures\ThrowingLogger;
use Kaly\Tasks\Worker;
use Kaly\Tasks\WorkerOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkerTest extends TestCase
{
    /**
     * @return iterable<string,array{string,string,int,string}>
     */
    public static function settlementFailures(): iterable
    {
        yield 'invalid payload' => ['test.record', '{"bogus":1}', 1, 'fail'];
        yield 'unknown type' => ['test.unknown', '{}', 1, 'fail'];
        yield 'retry' => ['test.record', '{"entry":"fail-me"}', 1, 'release'];
        yield 'final failure' => ['test.record', '{"entry":"fail-me"}', 2, 'fail'];
        yield 'exhausted attempts' => ['test.record', '{"entry":"ok"}', 3, 'fail'];
        yield 'completion' => ['test.record', '{"entry":"ok"}', 1, 'complete'];
    }

    #[DataProvider('settlementFailures')]
    public function testStorageFailuresPropagateWithoutAnotherSettlement(
        string $name,
        string $payload,
        int $attempt,
        string $operation,
    ): void {
        $reserved = new ReservedTask(1, $name, 'default', $payload, $attempt, 'owner');
        $outage = new RuntimeException('Storage unavailable');
        $store = $this->createMock(TaskStore::class);
        $store->expects($this->once())->method('recover')->willReturn(0);
        $store->expects($this->once())->method('reserve')->willReturn($reserved);
        foreach (['fail', 'release', 'complete'] as $method) {
            if ($method === $operation) {
                $store->expects($this->once())->method($method)->with($reserved)->willThrowException($outage);
            } else {
                $store->expects($this->never())->method($method);
            }
        }
        $registry = (new TaskRegistry())->register('test.record', RecordTask::class, retry: new RetryPolicy(maxAttempts: 2));
        $container = new Container(Definitions::create()->set(Journal::class, new Journal()));
        $worker = new Worker($store, $registry, new Injector($container));

        try {
            $worker->run(new WorkerOptions(timeout: 0, maxTasks: 1));
            $this->fail('The storage failure must terminate the worker');
        } catch (RuntimeException $e) {
            $this->assertSame($outage, $e);
        }
    }

    #[DataProvider('settlementFailures')]
    public function testThrowingLoggerDoesNotPreventSettlement(string $name, string $payload, int $attempt, string $operation): void
    {
        $reserved = new ReservedTask(1, $name, 'default', $payload, $attempt, 'owner');
        $store = $this->createMock(TaskStore::class);
        $store->expects($this->once())->method('recover')->willReturn(0);
        $store->expects($this->once())->method('reserve')->willReturn($reserved);
        foreach (['fail', 'release', 'complete'] as $method) {
            if ($method === $operation) {
                $store->expects($this->once())->method($method)->with($reserved)->willReturn(true);
            } else {
                $store->expects($this->never())->method($method);
            }
        }
        $registry = (new TaskRegistry())->register('test.record', RecordTask::class, retry: new RetryPolicy(maxAttempts: 2));
        $container = new Container(Definitions::create()->set(Journal::class, new Journal()));
        $worker = new Worker($store, $registry, new Injector($container), new ThrowingLogger());

        $this->assertSame(1, $worker->run(new WorkerOptions(timeout: 0, maxTasks: 1)));
    }

    public function testThrowingLoggerDoesNotStopTheLoopAfterALostLease(): void
    {
        $reserved = new ReservedTask(1, 'test.record', 'default', '{"entry":"ok"}', 1, 'owner');
        $store = $this->createMock(TaskStore::class);
        $store->expects($this->once())->method('recover')->willReturn(0);
        $store->expects($this->once())->method('reserve')->willReturn($reserved);
        $store->expects($this->once())->method('complete')->with($reserved)->willReturn(false);
        $registry = (new TaskRegistry())->register('test.record', RecordTask::class);
        $container = new Container(Definitions::create()->set(Journal::class, new Journal()));
        $worker = new Worker($store, $registry, new Injector($container), new ThrowingLogger());

        $this->assertSame(1, $worker->run(new WorkerOptions(timeout: 0, maxTasks: 1, sleepMs: 0, stopWhenEmpty: true)));
    }

    public function testThrowingLoggerDoesNotPreventLeaseRecovery(): void
    {
        $store = $this->createMock(TaskStore::class);
        $store->expects($this->once())->method('recover')->willReturn(1);
        $store->expects($this->once())->method('reserve')->willReturn(null);
        $worker = new Worker($store, new TaskRegistry(), new Injector(new Container()), new ThrowingLogger());

        $this->assertSame(0, $worker->run(new WorkerOptions(timeout: 0, sleepMs: 0, stopWhenEmpty: true)));
    }

    public function testThrowingLoggerDoesNotPreventStartupWithoutPcntl(): void
    {
        if (function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('Requires a platform without ext-pcntl');
        }
        $store = $this->createMock(TaskStore::class);
        $store->expects($this->once())->method('recover')->willReturn(0);
        $store->expects($this->once())->method('reserve')->willReturn(null);
        $worker = new Worker($store, new TaskRegistry(), new Injector(new Container()), new ThrowingLogger());

        $this->assertSame(0, $worker->run(new WorkerOptions(sleepMs: 0, stopWhenEmpty: true)));
    }

    public function testUnprioritizedQueuesAreReservedTogether(): void
    {
        $store = $this->createMock(TaskStore::class);
        $store->method('recover')->willReturn(0);
        $store->expects($this->once())->method('reserve')->with(['high', 'low'])->willReturn(null);
        $worker = new Worker($store, new TaskRegistry(), new Injector(new Container()));

        $this->assertSame(0, $worker->run(new WorkerOptions(queues: ['high', 'low'], timeout: 0, sleepMs: 0, stopWhenEmpty: true)));
    }

    public function testPrioritizedQueuesAreReservedOneByOneWithOneOwner(): void
    {
        $calls = [];
        $store = $this->createMock(TaskStore::class);
        $store->method('recover')->willReturn(0);
        $store
            ->expects($this->exactly(3))
            ->method('reserve')
            ->willReturnCallback(static function (array $queues, string $owner) use (&$calls): ?ReservedTask {
                $calls[] = [$queues, $owner];
                return null;
            });
        $worker = new Worker($store, new TaskRegistry(), new Injector(new Container()));

        $this->assertSame(
            0,
            $worker->run(new WorkerOptions(
                queues: ['high', 'mid', 'low'],
                timeout: 0,
                sleepMs: 0,
                stopWhenEmpty: true,
                prioritizeQueues: true,
            )),
        );
        $this->assertSame([['high'], ['mid'], ['low']], array_column($calls, 0));
        $this->assertCount(1, array_unique(array_column($calls, 1)));
    }

    public function testPrioritizedQueuesStopAtTheFirstReservation(): void
    {
        $reserved = new ReservedTask(1, 'test.record', 'default', '{"entry":"ok"}', 1, 'owner');
        $store = $this->createMock(TaskStore::class);
        $store->method('recover')->willReturn(0);
        $store->expects($this->once())->method('reserve')->with(['high'])->willReturn($reserved);
        $store->expects($this->once())->method('complete')->with($reserved)->willReturn(true);
        $registry = (new TaskRegistry())->register('test.record', RecordTask::class);
        $container = new Container(Definitions::create()->set(Journal::class, new Journal()));
        $worker = new Worker($store, $registry, new Injector($container));

        $this->assertSame(1, $worker->run(new WorkerOptions(queues: ['high', 'low'], timeout: 0, maxTasks: 1, prioritizeQueues: true)));
    }

    /**
     * A store handing out one task, expecting exactly one settlement.
     *
     * @return TaskStore&MockObject
     */
    private function storeSettling(ReservedTask $reserved, string $operation, mixed ...$with): TaskStore
    {
        $store = $this->createMock(TaskStore::class);
        $store->method('recover')->willReturn(0);
        $store->expects($this->once())->method('reserve')->willReturn($reserved);
        foreach (['fail', 'release', 'complete'] as $method) {
            if ($method === $operation) {
                $store->expects($this->once())->method($method)->with($reserved, ...$with)->willReturn(true);
            } else {
                $store->expects($this->never())->method($method);
            }
        }
        return $store;
    }

    private function runOnce(TaskStore $store, Journal $journal, ?Closure $around = null): void
    {
        $registry = (new TaskRegistry())
            ->register('test.record', RecordTask::class, retry: new RetryPolicy(maxAttempts: 3, delay: 10, maxDelay: 60))
            ->register('test.failing', FailingTask::class, retry: new RetryPolicy(maxAttempts: 3, delay: 10, maxDelay: 60));
        $container = new Container(Definitions::create()->set(Journal::class, $journal));
        $worker = new Worker($store, $registry, new Injector($container), around: $around);

        $this->assertSame(1, $worker->run(new WorkerOptions(timeout: 0, maxTasks: 1)));
    }

    public function testPermanentFailureFailsWithAttemptsLeft(): void
    {
        $reserved = new ReservedTask(1, 'test.failing', 'default', '{}', 1, 'owner');
        $journal = new Journal();

        $this->runOnce($this->storeSettling($reserved, 'fail', PermanentFailure::class . ': Invalid data'), $journal);
        $this->assertSame(['ran'], $journal->entries);
    }

    public function testRetryLaterReplacesTheBackoffBeyondMaxDelay(): void
    {
        $reserved = new ReservedTask(1, 'test.failing', 'default', '{"retryAfter":7200}', 1, 'owner');

        $this->runOnce($this->storeSettling($reserved, 'release', 7200, RetryLater::class . ': Rate limited'), new Journal());
    }

    public function testRetryLaterAcceptsAnImmediateRetry(): void
    {
        $reserved = new ReservedTask(1, 'test.failing', 'default', '{"retryAfter":0}', 2, 'owner');

        $this->runOnce($this->storeSettling($reserved, 'release', 0, RetryLater::class . ': Rate limited'), new Journal());
    }

    public function testRetryLaterFailsOnTheLastAttempt(): void
    {
        $reserved = new ReservedTask(1, 'test.failing', 'default', '{"retryAfter":5}', 3, 'owner');

        $this->runOnce($this->storeSettling($reserved, 'fail', RetryLater::class . ': Rate limited'), new Journal());
    }

    /**
     * @return iterable<string,array{int}>
     */
    public static function invalidRetryDelays(): iterable
    {
        yield 'negative' => [-1];
        yield 'too long' => [2_147_483_648];
    }

    #[DataProvider('invalidRetryDelays')]
    public function testRetryLaterRejectsInvalidDelays(int $seconds): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RetryLater($seconds);
    }

    public function testExecutionWrapperRunsTheTaskOnceWithItsMetadata(): void
    {
        $reserved = new ReservedTask(7, 'test.record', 'mail', '{"entry":"ok"}', 2, 'owner');
        $journal = new Journal();
        $runs = [];
        $around = static function (TaskRun $run, Closure $next) use (&$runs): void {
            $runs[] = $run;
            $next();
        };

        $this->runOnce($this->storeSettling($reserved, 'complete'), $journal, $around);
        $this->assertSame(['ok'], $journal->entries);
        $this->assertCount(1, $runs);
        $this->assertSame([7, 'test.record', 'mail', 2, 3], [
            $runs[0]->id,
            $runs[0]->name,
            $runs[0]->queue,
            $runs[0]->attempt,
            $runs[0]->maxAttempts,
        ]);
        $this->assertInstanceOf(RecordTask::class, $runs[0]->task);
    }

    public function testExecutionWrapperThatSkipsTheTaskFailsTheAttempt(): void
    {
        $reserved = new ReservedTask(1, 'test.record', 'default', '{"entry":"ok"}', 1, 'owner');
        $journal = new Journal();

        $this->runOnce(
            $this->storeSettling($reserved, 'release', 10, 'LogicException: The execution wrapper did not run the task'),
            $journal,
            static function (TaskRun $run, Closure $next): void {},
        );
        $this->assertSame([], $journal->entries);
    }

    public function testExecutionWrapperCannotRunTheTaskTwice(): void
    {
        $reserved = new ReservedTask(1, 'test.record', 'default', '{"entry":"ok"}', 1, 'owner');
        $journal = new Journal();

        $this->runOnce(
            $this->storeSettling($reserved, 'release', 10, 'LogicException: A task runs once per attempt'),
            $journal,
            static function (TaskRun $run, Closure $next): void {
                $next();
                $next();
            },
        );
        $this->assertSame(['ok'], $journal->entries);
    }

    public function testExecutionWrapperKeepsTheFailureClassification(): void
    {
        $reserved = new ReservedTask(1, 'test.failing', 'default', '{}', 1, 'owner');
        $cleaned = false;

        $this->runOnce($this->storeSettling($reserved, 'fail', PermanentFailure::class . ': Invalid data'), new Journal(), static function (
            TaskRun $run,
            Closure $next,
        ) use (&$cleaned): void {
            try {
                $next();
            } finally {
                $cleaned = true;
            }
        });
        $this->assertTrue($cleaned);
    }

    public function testExecutionWrapperDoesNotSeeUndecodableTasks(): void
    {
        $reserved = new ReservedTask(1, 'test.record', 'default', '{"bogus":1}', 1, 'owner');
        $wrapped = false;

        $this->runOnce(
            $this->storeSettling($reserved, 'fail', "Task 'test.record' payload has unknown keys: bogus"),
            new Journal(),
            static function (TaskRun $run, Closure $next) use (&$wrapped): void {
                $wrapped = true;
                $next();
            },
        );
        $this->assertFalse($wrapped);
    }
}
