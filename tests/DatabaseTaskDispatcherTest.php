<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use DateTimeImmutable;
use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Task\ScheduledTaskDispatcher;
use Kaly\Task\TaskDispatcher;
use Kaly\Tasks\DatabaseTaskDispatcher;
use Kaly\Tasks\TaskRegistry;
use Kaly\Tasks\Tests\Engine\OnSqlite;
use Kaly\Tasks\Tests\Fixtures\RecordTask;

final class DatabaseTaskDispatcherTest extends StoreTestCase
{
    use OnSqlite;

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
