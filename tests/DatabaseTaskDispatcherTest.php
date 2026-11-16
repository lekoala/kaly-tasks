<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Task\ScheduledTaskDispatcher;
use Kaly\Task\TaskDispatcher;
use Kaly\Tasks\DatabaseTaskDispatcher;
use Kaly\Tasks\Store\TaskChain;
use Kaly\Tasks\Store\TaskStore;
use Kaly\Tasks\TaskRegistry;
use Kaly\Tasks\TaskSequenceDispatcher;
use Kaly\Tasks\Tests\Engine\OnSqlite;
use Kaly\Tasks\Tests\Fixtures\LogTask;
use Kaly\Tasks\Tests\Fixtures\RecordTask;
use ReflectionMethod;

final class DatabaseTaskDispatcherTest extends StoreTestCase
{
    use OnSqlite;

    public function testSequenceCommitsAndRollsBackWithItsApplicationTransaction(): void
    {
        $registry = (new TaskRegistry())->register('test.record', RecordTask::class);
        $container = new Container(Definitions::create()->set(
            TaskSequenceDispatcher::class,
            new DatabaseTaskDispatcher($this->store(), $registry),
        ));
        $tasks = $container->get(TaskSequenceDispatcher::class);
        $at = new DateTimeImmutable('+1 hour');
        $this->pdo()->beginTransaction();
        $tasks->sequence([new RecordTask('a'), new RecordTask('b')], notBefore: $at);
        $this->pdo()->rollBack();
        $this->assertSame(0, $this->countTasks());
        $this->pdo()->beginTransaction();
        $tasks->sequence([new RecordTask('a'), new RecordTask('b')], notBefore: $at);
        $this->pdo()->commit();
        $this->assertSame(1, $this->countTasks());
        $this->assertNull($this->store()->reserve(['default'], 'a', 60));
        $this->age(3610);
        $a = $this->store()->reserve(['default'], 'a', 60);
        $this->assertNotNull($a);
        $this->assertSame('{"entry":"a"}', $a->payload);
        $this->assertTrue($this->store()->complete($a));
        $this->assertSame('{"entry":"b"}', $this->store()->reserve(['default'], 'b', 60)?->payload);
    }

    public function testOneStepSequenceIsAnOrdinaryDispatch(): void
    {
        $tasks = new DatabaseTaskDispatcher($this->store(), (new TaskRegistry())->register('test.record', RecordTask::class));
        $this->assertTrue($tasks->sequence([new RecordTask('a')]));
        $this->assertNull($this->row(1)['chain']);
        $this->assertSame('{"entry":"a"}', $this->store()->reserve(['default'], 'a', 60)?->payload);
    }

    public function testSequenceRejectsAnEmptyList(): void
    {
        $tasks = new DatabaseTaskDispatcher($this->store(), new TaskRegistry());
        $this->expectException(InvalidArgumentException::class);
        // Reflection exercises invalid runtime input outside the static non-empty-list contract.
        (new ReflectionMethod($tasks, 'sequence'))->invoke($tasks, []);
    }

    public function testSequenceRejectsAnAssociativeArray(): void
    {
        $tasks = new DatabaseTaskDispatcher($this->store(), (new TaskRegistry())->register('test.record', RecordTask::class));
        $this->expectException(InvalidArgumentException::class);
        // The PHPDoc contract already excludes associative arrays at analysis time.
        (new ReflectionMethod($tasks, 'sequence'))->invoke($tasks, ['first' => new RecordTask('a')]);
    }

    public function testSequenceValidatesAllTasksBeforeInsertingTheFirst(): void
    {
        $tasks = new DatabaseTaskDispatcher($this->store(), (new TaskRegistry())->register('test.record', RecordTask::class));
        try {
            $tasks->sequence([new RecordTask('a'), new LogTask(2)]);
            $this->fail('An unregistered continuation must fail');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, $this->countTasks());
        }
    }

    public function testSequenceRejectsDeduplicatedTypesAfterTheFirst(): void
    {
        $registry = (new TaskRegistry())->register('test.record', RecordTask::class, deduplicateBy: 'entry');
        $store = $this->createMock(TaskStore::class);
        $store->expects($this->never())->method('push');
        $tasks = new DatabaseTaskDispatcher($store, $registry);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only the first task in a sequence may deduplicate');
        $tasks->sequence([new RecordTask('a'), new RecordTask('b')]);
    }

    public function testSequenceReportsDeduplicationWithoutReplacingTheOriginalContinuation(): void
    {
        $registry = (new TaskRegistry())
            ->register('test.record', RecordTask::class, deduplicateBy: 'entry')
            ->register('test.log', LogTask::class);
        $tasks = new DatabaseTaskDispatcher($this->store(), $registry);
        $this->assertTrue($tasks->sequence([new RecordTask('execution'), new LogTask(1)]));
        $this->assertFalse($tasks->sequence([new RecordTask('execution'), new LogTask(2)]));
        $reserved = $this->store()->reserve(['default'], 'owner', 60);
        $this->assertNotNull($reserved);
        $this->assertEquals([$registry->encode(new LogTask(1))], TaskChain::decode($reserved->chain));
    }

    public function testScheduledDispatchCommitsAndRollsBackWithTheApplication(): void
    {
        $container = new Container(Definitions::create()->set(
            ScheduledTaskDispatcher::class,
            new DatabaseTaskDispatcher($this->store(), (new TaskRegistry())->register('test.record', RecordTask::class)),
        )->alias(TaskDispatcher::class, ScheduledTaskDispatcher::class));
        $tasks = $container->get(ScheduledTaskDispatcher::class);
        $this->assertSame($tasks, $container->get(TaskDispatcher::class));
        $notBefore = new DateTimeImmutable('+1 hour');
        $this->pdo()->beginTransaction();
        $tasks->dispatch(new RecordTask('rolled back'), notBefore: $notBefore);
        $this->pdo()->rollBack();
        $this->assertSame(0, $this->countTasks());

        $this->pdo()->beginTransaction();
        $tasks->dispatch(new RecordTask('committed'), notBefore: $notBefore);
        $this->pdo()->commit();
        $this->assertSame(1, $this->countTasks());
        $this->assertNull($this->store()->reserve(['default'], 'owner', 60));
        $this->age(3610);
        $reserved = $this->store()->reserve(['default'], 'owner', 60);
        $this->assertNotNull($reserved);
        $this->assertSame('test.record', $reserved->name);
        $this->assertSame('{"entry":"committed"}', $reserved->payload);
    }
}
