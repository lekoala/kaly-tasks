<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use InvalidArgumentException;
use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\EncodedTask;
use Kaly\Tasks\Store\PostgresTaskStore;
use Kaly\Tasks\Store\ReservedTask;
use Kaly\Tasks\TaskRegistry;
use Kaly\Tasks\Tests\Fixtures\Priority;
use Kaly\Tasks\Tests\Fixtures\TypedTask;
use PHPUnit\Framework\Attributes\DataProvider;

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

    public function testRejectsTableNamesThatWouldTruncateIndexNames(): void
    {
        new PostgresTaskStore(new PdoConnection($this->pdo), str_repeat('t', 55));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be at most 55 characters');
        new PostgresTaskStore(new PdoConnection($this->pdo), str_repeat('t', 56));
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function invalidBounds(): iterable
    {
        yield 'no lease' => ['lease'];
        yield 'negative delay' => ['delay'];
        yield 'no limit' => ['limit'];
        yield 'negative retention' => ['retention'];
        yield 'negative failed retention' => ['failedRetention'];
        yield 'overflowing retention' => ['overflow'];
    }

    #[DataProvider('invalidBounds')]
    public function testRejectsOutOfRangeDurationsAndLimits(string $case): void
    {
        $task = new ReservedTask(1, 'a', '{}', 1, 'owner');

        $this->expectException(InvalidArgumentException::class);
        match ($case) {
            'lease' => $this->store->reserve(['default'], 'owner', 0),
            'delay' => $this->store->release($task, -5, 'no'),
            'limit' => $this->store->failed(0),
            'retention' => $this->store->purge(-1),
            'failedRetention' => $this->store->purge(60, -1),
            default => $this->store->purge(2_147_483_648),
        };
    }

    public function testReservesInOrderAndLeasesToTheOwner(): void
    {
        $this->store->push(new EncodedTask('a', 'default', '{"n":1}'));
        $this->store->push(new EncodedTask('b', 'default', '{"n":2}'));

        $first = $this->reserve();
        $second = $this->reserve(owner: 'owner-b');

        $this->assertSame(['a', '{"n":1}', 1, 'owner-a'], [$first?->name, $first?->payload, $first?->attempts, $first?->owner]);
        $this->assertSame('b', $second?->name);
        $this->assertNull($this->reserve());
        $this->assertSame(2, $this->countTasks('running'));
    }

    public function testPayloadsRoundTripVerbatim(): void
    {
        $registry = (new TaskRegistry())->register('test.typed', TypedTask::class);
        // JSONB would reorder these keys and reject the NUL escape
        $task = new TypedTask('doc-1', 1, 0.5, false, ['zz' => 1, 'a' => ['y' => 2, 'b' => 3], "nul\0" => "\0"], Priority::Low);
        $this->store->push($registry->encode($task));

        $reserved = $this->reserve();

        $this->assertNotNull($reserved);
        $this->assertSame($registry->encode($task)->payload, $reserved->payload);
        $decoded = $registry->decode($reserved->name, $reserved->payload);
        $this->assertInstanceOf(TypedTask::class, $decoded);
        $this->assertSame($task->tags, $decoded->tags);
    }

    public function testErrorsAreStoredAsBoundedValidText(): void
    {
        $this->store->push(new EncodedTask('a', 'default', '{}'));
        $this->store->push(new EncodedTask('b', 'default', '{}'));

        $this->assertTrue($this->store->fail($this->reserve() ?? $this->fail('No task'), "Bad \xFF byte\0"));
        $this->assertTrue($this->store->release($this->reserve() ?? $this->fail('No task'), 0, str_repeat('é', 3000)));

        $this->assertSame('Bad ? byte', $this->row(1)['last_error']);
        $error = $this->row(2)['last_error'];
        $this->assertIsString($error);
        // A cut multibyte character is dropped
        $this->assertSame(4095, strlen($error));
        $this->assertStringEndsWith('é…', $error);
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
            [['id' => $task->id, 'queue' => 'default', 'name' => 'a', 'payload' => '{"n":1}', 'attempts' => 1, 'error' => 'Broken']],
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
