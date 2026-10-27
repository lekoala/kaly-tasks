<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Di\Injector;
use Kaly\Tasks\RetryPolicy;
use Kaly\Tasks\Store\ReservedTask;
use Kaly\Tasks\Store\TaskStore;
use Kaly\Tasks\TaskRegistry;
use Kaly\Tasks\Tests\Fixtures\Journal;
use Kaly\Tasks\Tests\Fixtures\RecordTask;
use Kaly\Tasks\Tests\Fixtures\ThrowingLogger;
use Kaly\Tasks\Worker;
use Kaly\Tasks\WorkerOptions;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $reserved = new ReservedTask(1, $name, $payload, $attempt, 'owner');
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
        $reserved = new ReservedTask(1, $name, $payload, $attempt, 'owner');
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
        $reserved = new ReservedTask(1, 'test.record', '{"entry":"ok"}', 1, 'owner');
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
}
