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
}
