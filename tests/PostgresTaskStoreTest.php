<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use InvalidArgumentException;
use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\EncodedTask;
use Kaly\Tasks\Store\PostgresTaskStore;
use Kaly\Tasks\Store\ReservedTask;

class PostgresTaskStoreTest extends PostgresTestCase
{
    private function reserve(string $queue = 'default', string $owner = 'owner-a'): ?ReservedTask
    {
        return $this->store->reserve([$queue], $owner, 60);
    }

    public function testRejectsUnsafeTableNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PostgresTaskStore(new PdoConnection($this->pdo), 'tasks; DROP TABLE users');
    }

    public function testReservesInOrderAndLeasesToTheOwner(): void
    {
        $this->store->push(new EncodedTask('a', 'default', '{"n":1}'));
        $this->store->push(new EncodedTask('b', 'default', '{"n":2}'));

        $first = $this->reserve();
        $second = $this->reserve(owner: 'owner-b');

        $this->assertSame(['a', '{"n": 1}', 1, 'owner-a'], [$first?->name, $first?->payload, $first?->attempts, $first?->owner]);
        $this->assertSame('b', $second?->name);
        $this->assertNull($this->reserve());
        $this->assertSame(2, $this->countTasks('running'));
    }

    public function testReservesOnlyTheRequestedQueues(): void
    {
        $this->store->push(new EncodedTask('a', 'mail', '{}'));

        $this->assertNull($this->reserve('default'));
        $this->assertSame('a', $this->store->reserve(['default', 'mail'], 'owner', 60)?->name);
    }

    public function testDeduplicatesByNameAndKeyAcrossStatuses(): void
    {
        $this->assertTrue($this->store->push(new EncodedTask('a', 'default', '{}', 'event-1')));
        $this->assertFalse($this->store->push(new EncodedTask('a', 'default', '{}', 'event-1')));
        $this->assertTrue($this->store->push(new EncodedTask('b', 'default', '{}', 'event-1')));
        $this->assertTrue($this->store->push(new EncodedTask('a', 'default', '{}')));
        $this->assertTrue($this->store->push(new EncodedTask('a', 'default', '{}')));

        $task = $this->reserve();
        $this->assertNotNull($task);
        $this->store->complete($task);
        $this->assertFalse($this->store->push(new EncodedTask('a', 'default', '{}', 'event-1')), 'Done tasks keep their key until purged');

        $this->age(3600);
        $this->assertSame(1, $this->store->purge(60));
        $this->assertTrue($this->store->push(new EncodedTask('a', 'default', '{}', 'event-1')));
    }

    public function testOnlyTheOwnerSettlesATask(): void
    {
        $this->store->push(new EncodedTask('a', 'default', '{}'));
        $task = $this->reserve();
        $this->assertNotNull($task);
        $impostor = new ReservedTask($task->id, $task->name, $task->payload, $task->attempts, 'owner-b');

        $this->assertFalse($this->store->complete($impostor));
        $this->assertFalse($this->store->fail($impostor, 'no'));
        $this->assertFalse($this->store->release($impostor, 0, 'no'));
        $this->assertTrue($this->store->complete($task));
        $this->assertFalse($this->store->complete($task), 'A settled task is no longer owned');
        $this->assertSame('done', $this->row($task->id)['status']);
    }

    public function testReleaseDelaysTheNextAttempt(): void
    {
        $this->store->push(new EncodedTask('a', 'default', '{}'));
        $task = $this->reserve();
        $this->assertNotNull($task);

        $this->assertTrue($this->store->release($task, 30, 'Timeout'));
        $this->assertNull($this->reserve(), 'Not available before its delay');

        $this->age(31);
        $retried = $this->reserve();
        $this->assertSame(2, $retried?->attempts);
        $this->assertSame('Timeout', $this->row($task->id)['last_error']);
    }

    public function testRecoversExpiredLeasesOnly(): void
    {
        $this->store->push(new EncodedTask('a', 'default', '{}'));
        $this->store->push(new EncodedTask('b', 'default', '{}'));
        $crashed = $this->reserve();
        $this->assertNotNull($crashed);

        $this->assertSame(0, $this->store->recover(), 'A live lease is kept');
        $this->age(61);
        $alive = $this->reserve(owner: 'owner-b');
        $this->assertSame(1, $this->store->recover());

        $recovered = $this->reserve(owner: 'owner-c');
        $this->assertSame([$crashed->id, 2], [$recovered?->id, $recovered?->attempts]);
        $this->assertFalse($this->store->complete($crashed), 'The crashed owner lost the task');
        $this->assertNotNull($alive);
        $this->assertTrue($this->store->complete($alive));
    }

    public function testFailedTasksCanBeListedAndRetried(): void
    {
        $this->store->push(new EncodedTask('a', 'default', '{"n":1}'));
        $task = $this->reserve();
        $this->assertNotNull($task);
        $this->store->fail($task, 'Broken');

        $this->assertSame(
            [['id' => $task->id, 'queue' => 'default', 'name' => 'a', 'payload' => '{"n": 1}', 'attempts' => 1, 'error' => 'Broken']],
            $this->store->failed(),
        );
        $this->assertTrue($this->store->retryFailed($task->id));
        $this->assertFalse($this->store->retryFailed($task->id));
        $this->assertSame(1, $this->reserve()?->attempts);
    }

    public function testPurgeKeepsRecentAndUnfinishedTasks(): void
    {
        $this->store->push(new EncodedTask('a', 'default', '{}'));
        $this->store->push(new EncodedTask('b', 'default', '{}'));
        $this->store->push(new EncodedTask('c', 'default', '{}'));
        $done = $this->reserve();
        $failed = $this->reserve();
        $this->assertNotNull($done);
        $this->assertNotNull($failed);
        $this->store->complete($done);
        $this->store->fail($failed, 'Broken');

        $this->assertSame(0, $this->store->purge(60));
        $this->age(120);
        $this->assertSame(1, $this->store->purge(60));
        $this->assertSame(2, $this->countTasks());
        $this->assertSame(1, $this->store->purge(60, 60), 'Failed tasks are purged on demand');
        $this->assertSame(['pending'], [$this->row(3)['status']]);
    }

    public function testFailedTasksKeepTheirDeduplicationKey(): void
    {
        $this->store->push(new EncodedTask('a', 'default', '{}', 'event-1'));
        $task = $this->reserve();
        $this->assertNotNull($task);
        $this->store->fail($task, 'Broken');

        $this->assertFalse($this->store->push(new EncodedTask('a', 'default', '{}', 'event-1')));
        $this->assertTrue($this->store->retryFailed($task->id));
    }
}
