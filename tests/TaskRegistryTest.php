<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use InvalidArgumentException;
use Kaly\Tasks\RetryPolicy;
use Kaly\Tasks\TaskRegistry;
use Kaly\Tasks\Tests\Fixtures\Journal;
use Kaly\Tasks\Tests\Fixtures\Priority;
use Kaly\Tasks\Tests\Fixtures\RecordTask;
use Kaly\Tasks\Tests\Fixtures\TypedTask;
use Kaly\Tasks\WorkerOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final readonly class PrivateDataTask
{
    public function __construct(
        private string $id,
    ) {}

    public function __invoke(): string
    {
        return $this->id;
    }
}

final readonly class ServiceTask
{
    public function __construct(
        public Journal $journal,
    ) {}

    public function __invoke(): void {}
}

final readonly class UnionTask
{
    public function __construct(
        public int|string $id,
    ) {}

    public function __invoke(): void {}
}

final class PlainParameterTask
{
    public string $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }

    public function __invoke(): void {}
}

final readonly class NotInvocableTask
{
    public function __construct(
        public string $id,
    ) {}
}

class TaskRegistryTest extends TestCase
{
    public function testRoundTripsEveryStorableType(): void
    {
        $registry = (new TaskRegistry())->register('test.typed', TypedTask::class);
        $task = new TypedTask('doc-1', 3, 1.0, true, ['a', ['b' => null, 'c' => 1.5]], Priority::High, 'ü/é', 5);

        $encoded = $registry->encode($task);

        $this->assertSame('test.typed', $encoded->name);
        $this->assertSame('default', $encoded->queue);
        $this->assertSame(
            '{"id":"doc-1","count":3,"ratio":1.0,"urgent":true,"tags":["a",{"b":null,"c":1.5}],"priority":"high","note":"ü/é","retries":5}',
            $encoded->payload,
        );
        $this->assertEquals($task, $registry->decode('test.typed', $encoded->payload));
    }

    public function testMissingOptionalParametersTakeTheirDefault(): void
    {
        $registry = (new TaskRegistry())->register('test.typed', TypedTask::class);

        $task = $registry->decode('test.typed', '{"id":"doc-1","count":3,"ratio":1,"urgent":false,"tags":[],"priority":"low"}');

        $this->assertEquals(new TypedTask('doc-1', 3, 1.0, false, [], Priority::Low, null, 3), $task);
    }

    public function testDeduplicationKeyComesFromTheDeclaredParameter(): void
    {
        $registry = (new TaskRegistry())->register('test.record', RecordTask::class, queue: 'mail', deduplicateBy: 'entry');

        $encoded = $registry->encode(new RecordTask('event-42'));

        $this->assertSame('mail', $encoded->queue);
        $this->assertSame('event-42', $encoded->deduplicationKey);
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'unknown name' => ['test.other', '{}'];
        yield 'invalid json' => ['test.typed', '{'];
        yield 'list' => ['test.typed', '[1]'];
        yield 'unknown key' => ['test.typed', '{"id":"a","count":1,"ratio":1,"urgent":true,"tags":[],"priority":"low","extra":1}'];
        yield 'missing key' => ['test.typed', '{"count":1,"ratio":1,"urgent":true,"tags":[],"priority":"low"}'];
        yield 'wrong type' => ['test.typed', '{"id":1,"count":1,"ratio":1,"urgent":true,"tags":[],"priority":"low"}'];
        yield 'null for non-nullable' => ['test.typed', '{"id":null,"count":1,"ratio":1,"urgent":true,"tags":[],"priority":"low"}'];
        yield 'unknown enum case' => ['test.typed', '{"id":"a","count":1,"ratio":1,"urgent":true,"tags":[],"priority":"urgent"}'];
    }

    #[DataProvider('invalidPayloads')]
    public function testDecodeRejectsInvalidPayloads(string $name, string $payload): void
    {
        $registry = (new TaskRegistry())->register('test.typed', TypedTask::class);

        $this->expectException(UnexpectedValueException::class);
        $registry->decode($name, $payload);
    }

    public function testEncodeRejectsUnregisteredTasks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Task ' . RecordTask::class . ' is not registered');
        (new TaskRegistry())->encode(new RecordTask('a'));
    }

    public function testEncodeRejectsObjectsInArrays(): void
    {
        $registry = (new TaskRegistry())->register('test.typed', TypedTask::class);

        $this->expectException(InvalidArgumentException::class);
        $registry->encode(new TypedTask('a', 1, 1.0, true, [new Journal()], Priority::Low));
    }

    /**
     * @return iterable<string,array{string,class-string,string|null}>
     */
    public static function invalidRegistrations(): iterable
    {
        yield 'bad name' => ['Test Record', RecordTask::class, null];
        yield 'private data' => ['test.private', PrivateDataTask::class, null];
        yield 'service in data' => ['test.service', ServiceTask::class, null];
        yield 'union type' => ['test.union', UnionTask::class, null];
        yield 'not promoted' => ['test.plain', PlainParameterTask::class, null];
        yield 'not invocable' => ['test.not-invocable', NotInvocableTask::class, null];
        yield 'deduplicate by unknown' => ['test.record', RecordTask::class, 'missing'];
        yield 'deduplicate by float' => ['test.typed', TypedTask::class, 'ratio'];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('invalidRegistrations')]
    public function testRegisterValidatesTheTaskClass(string $name, string $class, ?string $deduplicateBy): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TaskRegistry())->register($name, $class, deduplicateBy: $deduplicateBy);
    }

    public function testNamesAreRegisteredOnce(): void
    {
        $registry = (new TaskRegistry())->register('test.record', RecordTask::class);

        $this->expectExceptionMessage("Task name 'test.record' is already registered");
        $registry->register('test.record', TypedTask::class);
    }

    public function testClassesAreRegisteredOnce(): void
    {
        $registry = (new TaskRegistry())->register('test.record', RecordTask::class);

        $this->expectExceptionMessage('Task ' . RecordTask::class . " is already registered as 'test.record'");
        $registry->register('test.record-again', RecordTask::class);
    }

    public function testRetryPolicyBacksOffExponentiallyUpToTheCap(): void
    {
        $retry = new RetryPolicy(maxAttempts: 5, delay: 10, multiplier: 3, maxDelay: 60);

        $this->assertSame([10, 30, 60, 60], array_map($retry->delayAfter(...), [1, 2, 3, 4]));
        $this->assertSame(1, RetryPolicy::never()->maxAttempts);
    }

    public function testWorkerTimeoutMustBeShorterThanTheLease(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WorkerOptions(lease: 60, timeout: 60);
    }
}
