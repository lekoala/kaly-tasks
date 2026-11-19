<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Kaly\Tasks\IdleWaiter;
use Kaly\Tasks\Store\ReservedTask;
use Kaly\Tasks\Store\TaskStore;
use Kaly\Tasks\TaskRegistry;
use Kaly\Tasks\Tests\Fixtures\Journal;
use Kaly\Tasks\Tests\Fixtures\RecordTask;
use Kaly\Tasks\Worker;
use Kaly\Tasks\WorkerOptions;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RecordingWaiter implements IdleWaiter
{
    /**
     * @var list<string>
     */
    public array $events = [];

    /**
     * @var list<array{queues:list<string>,limit:int}>
     */
    public array $waits = [];

    public bool $signals = true;

    public bool $stopOnWait = false;

    public function start(): void
    {
        $this->events[] = 'start';
    }

    public function wait(array $queues, int $limitMs): bool
    {
        $this->events[] = 'wait';
        $this->waits[] = ['queues' => $queues, 'limit' => $limitMs];
        if ($this->stopOnWait) {
            throw new RuntimeException('stop the loop');
        }
        return $this->signals;
    }

    public function stop(): void
    {
        $this->events[] = 'stop';
    }

    public function close(): void
    {
        $this->events[] = 'close';
    }
}

final class WorkerIdleWaiterTest extends TestCase
{
    private Journal $journal;

    protected function setUp(): void
    {
        $this->journal = new Journal();
    }

    private function worker(TaskStore $store, IdleWaiter $waiter): Worker
    {
        $registry = (new TaskRegistry())->register('test.record', RecordTask::class);
        return new Worker($store, $registry, fn(object $task) => $task($this->journal), waiter: $waiter);
    }

    public function testTheWaiterStartsBeforeTheFirstReservationAndClosesAfterTheRun(): void
    {
        $waiter = new RecordingWaiter();
        $reserved = new ReservedTask(1, 'test.record', 'default', '{"entry":"ok"}', 1, 'owner');
        $store = $this->createMock(TaskStore::class);
        $store
            ->expects($this->once())
            ->method('recover')
            ->willReturnCallback(static function () use ($waiter): int {
                $waiter->events[] = 'recover';
                return 0;
            });
        $store
            ->expects($this->once())
            ->method('reserve')
            ->willReturnCallback(static function () use ($waiter, $reserved): ReservedTask {
                $waiter->events[] = 'reserve';
                return $reserved;
            });
        $store->expects($this->once())->method('complete')->with($reserved)->willReturn(true);

        $this->assertSame(1, $this->worker($store, $waiter)->run(new WorkerOptions(timeout: 0, maxTasks: 1)));

        $this->assertSame(['start', 'recover', 'reserve', 'close'], $waiter->events);
        $this->assertSame([], $waiter->waits);
    }

    public function testTheWaiterOnlyRunsAfterEveryQueueWasChecked(): void
    {
        $waiter = new RecordingWaiter();
        $waiter->stopOnWait = true;
        $calls = [];
        $store = $this->createMock(TaskStore::class);
        $store->method('recover')->willReturn(0);
        $store
            ->expects($this->exactly(3))
            ->method('reserve')
            ->willReturnCallback(static function (array $queues) use (&$calls): ?ReservedTask {
                $calls[] = $queues;
                return null;
            });

        try {
            $this->worker($store, $waiter)->run(new WorkerOptions(
                queues: ['high', 'mid', 'low'],
                timeout: 0,
                maxRuntime: 100,
                sleepMs: 0,
                prioritizeQueues: true,
            ));
            $this->fail('The waiting exception must stop the loop');
        } catch (RuntimeException $e) {
            $this->assertSame('stop the loop', $e->getMessage());
        }

        $this->assertSame([['high'], ['mid'], ['low']], $calls);
        $this->assertSame(['start', 'wait', 'close'], $waiter->events);
        $this->assertSame(['high', 'mid', 'low'], $waiter->waits[0]['queues']);
    }

    public function testStopWhenEmptyDoesNotSubscribeOrWait(): void
    {
        $waiter = new RecordingWaiter();
        $store = $this->createMock(TaskStore::class);
        $store->method('recover')->willReturn(0);
        $store->expects($this->once())->method('reserve')->willReturn(null);

        $this->assertSame(0, $this->worker($store, $waiter)->run(new WorkerOptions(timeout: 0, stopWhenEmpty: true)));

        $this->assertSame([], $waiter->events);
    }

    public function testTheWaitIsBoundedByMaxRuntime(): void
    {
        $waiter = new RecordingWaiter();
        $waiter->stopOnWait = true;
        $store = $this->createStub(TaskStore::class);
        $store->method('recover')->willReturn(0);
        $store->method('reserve')->willReturn(null);

        try {
            $this->worker($store, $waiter)->run(new WorkerOptions(timeout: 0, maxRuntime: 1, recoverInterval: 0));
        } catch (RuntimeException $e) {
            $this->assertSame('stop the loop', $e->getMessage());
        }

        $this->assertCount(1, $waiter->waits);
        $this->assertGreaterThan(0, $waiter->waits[0]['limit']);
        $this->assertLessThanOrEqual(1000, $waiter->waits[0]['limit']);
    }

    public function testTheWaitIsBoundedByTheNextRecovery(): void
    {
        $waiter = new RecordingWaiter();
        $waiter->stopOnWait = true;
        $store = $this->createStub(TaskStore::class);
        $store->method('recover')->willReturn(0);
        $store->method('reserve')->willReturn(null);

        try {
            $this->worker($store, $waiter)->run(new WorkerOptions(timeout: 0, maxRuntime: 100, recoverInterval: 1));
        } catch (RuntimeException $e) {
            $this->assertSame('stop the loop', $e->getMessage());
        }

        $this->assertCount(1, $waiter->waits);
        $this->assertGreaterThan(0, $waiter->waits[0]['limit']);
        $this->assertLessThanOrEqual(1000, $waiter->waits[0]['limit']);
    }

    public function testTheWaiterReceivesThePolledQueues(): void
    {
        $waiter = new RecordingWaiter();
        $waiter->stopOnWait = true;
        $store = $this->createMock(TaskStore::class);
        $store->method('recover')->willReturn(0);
        $store->expects($this->once())->method('reserve')->with(['high', 'low'])->willReturn(null);

        try {
            $this->worker($store, $waiter)->run(new WorkerOptions(queues: ['high', 'low'], timeout: 0, maxRuntime: 100));
        } catch (RuntimeException $e) {
            $this->assertSame('stop the loop', $e->getMessage());
        }

        $this->assertSame(['high', 'low'], $waiter->waits[0]['queues']);
    }

    public function testTheWaiterClosesWhenTheReservationFails(): void
    {
        $waiter = new RecordingWaiter();
        $store = $this->createStub(TaskStore::class);
        $store->method('recover')->willReturn(0);
        $store->method('reserve')->willThrowException(new RuntimeException('storage unavailable'));

        try {
            $this->worker($store, $waiter)->run(new WorkerOptions(timeout: 0));
            $this->fail('The storage failure must terminate the worker');
        } catch (RuntimeException $e) {
            $this->assertSame('storage unavailable', $e->getMessage());
        }

        $this->assertSame(['start', 'close'], $waiter->events);
    }
}
