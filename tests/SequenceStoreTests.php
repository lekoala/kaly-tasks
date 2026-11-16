<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Closure;
use InvalidArgumentException;
use Kaly\Tasks\Connection\Connection;
use Kaly\Tasks\Connection\TransactionalConnection;
use Kaly\Tasks\EncodedTask;
use Kaly\Tasks\Store\TaskChain;
use Kaly\Tasks\Tests\Fixtures\InterruptedSequenceConnection;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use UnexpectedValueException;

/** The sequence storage contract, inherited by every engine and adapter suite. */
trait SequenceStoreTests
{
    public function testSequenceActivatesOneStepAtATimeAcrossQueues(): void
    {
        $payload = '{ "z": 1, "a": "\\u0000" }';
        $this->store()->push(new EncodedTask('a', 'default', '{}', chain: [
            new EncodedTask('b', 'mail', $payload),
            new EncodedTask('c', 'default', '{}'),
        ]));
        $this->assertSame(1, $this->countTasks());
        $a = $this->store()->reserve(['default'], 'a', 60);
        $this->assertNotNull($a);
        $this->assertCount(2, TaskChain::decode($a->chain));
        $this->assertNull($this->store()->reserve(['mail'], 'b', 60));
        $this->assertTrue($this->store()->complete($a));
        $this->assertNull($this->row($a->id)['chain']);
        $this->assertFalse($this->store()->complete($a));
        $this->assertSame(2, $this->countTasks());
        $this->assertNull($this->store()->reserve(['default'], 'c', 60));
        $b = $this->store()->reserve(['mail'], 'b', 60);
        $this->assertNotNull($b);
        $this->assertSame(['b', $payload, 1], [$b->name, $b->payload, $b->attempts]);
        $this->assertCount(1, TaskChain::decode($b->chain));
        $this->assertTrue($this->store()->complete($b));
        $c = $this->store()->reserve(['default'], 'c', 60);
        $this->assertNotNull($c);
        $this->assertSame('c', $c->name);
        $this->assertNull($c->chain);
        $this->assertTrue($this->store()->complete($c));
        $this->assertSame(3, $this->countTasks('done'));
    }

    public function testSequenceKeepsItsContinuationAcrossRetriesAndManualRecovery(): void
    {
        $this->store()->push(new EncodedTask('a', 'default', '{}', chain: [new EncodedTask('b', 'default', '{}')]));
        $a = $this->reserve();
        $this->assertNotNull($a);
        $this->assertTrue($this->store()->release($a, 30, 'Retry'));
        $this->assertNull($this->reserve());
        $this->age(31);
        $retry = $this->reserve();
        $this->assertNotNull($retry);
        $this->assertSame(2, $retry->attempts);
        $this->assertEquals($a->chain, $retry->chain);
        $this->assertTrue($this->store()->fail($retry, 'Failed'));
        $this->assertSame(1, $this->countTasks());
        $this->assertTrue($this->store()->retryFailed($a->id));
        $recovered = $this->reserve();
        $this->assertNotNull($recovered);
        $this->assertSame(1, $recovered->attempts);
        $this->assertEquals($a->chain, $recovered->chain);
        $this->assertTrue($this->store()->complete($recovered));
        $this->assertSame(['b', 1], [($b = $this->reserve())?->name, $b?->attempts]);
    }

    public function testDeletingOrPurgingAFailedSequenceAbandonsItsContinuation(): void
    {
        foreach (['delete', 'purge'] as $operation) {
            $this->store()->push(new EncodedTask('a', 'default', '{}', chain: [new EncodedTask('b', 'default', '{}')]));
            $task = $this->reserve();
            $this->assertNotNull($task);
            $this->assertTrue($this->store()->fail($task, 'Failed'));
            if ($operation === 'delete') {
                $this->assertTrue($this->store()->deleteFailed($task->id));
            } else {
                $this->age(120);
                $this->assertSame(1, $this->store()->purge(60, 60));
            }
            $this->assertSame(0, $this->countTasks());
            $this->assertNull($this->reserve());
        }
    }

    public function testRecoveredSequenceRejectsThePreviousOwner(): void
    {
        $this->store()->push(new EncodedTask('a', 'default', '{}', chain: [new EncodedTask('b', 'default', '{}')]));
        $old = $this->reserve();
        $this->assertNotNull($old);
        $this->age(61);
        $this->assertSame(1, $this->store()->recover());
        $new = $this->reserve(owner: 'new');
        $this->assertNotNull($new);
        $this->assertFalse($this->store()->complete($old));
        $this->assertSame(1, $this->countTasks());
        $this->assertTrue($this->store()->complete($new));
        $this->assertSame('b', $this->reserve()?->name);
    }

    public function testSequenceCanCompleteAfterLeaseExpiryUntilItIsRecovered(): void
    {
        $this->store()->push(new EncodedTask('a', 'default', '{}', chain: [new EncodedTask('b', 'default', '{}')]));
        $a = $this->reserve();
        $this->assertNotNull($a);
        $this->age(61);
        $this->assertTrue($this->store()->complete($a));
        $this->assertSame('b', $this->reserve()?->name);
    }

    public function testFirstStepDeduplicationPreservesTheWholeOriginalSubmission(): void
    {
        $original = new EncodedTask('a', 'default', '{}', 'execution', [new EncodedTask('b', 'default', '{}')]);
        $this->assertTrue($this->store()->push($original));
        $this->assertFalse($this->store()->push(new EncodedTask('a', 'default', '{}', 'execution', [new EncodedTask(
            'c',
            'default',
            '{}',
        )])));
        $a = $this->reserve();
        $this->assertNotNull($a);
        $this->assertTrue($this->store()->complete($a));
        $this->assertFalse($this->store()->push($original));
        $this->age(120);
        $this->assertSame(1, $this->store()->purge(60));
        $this->assertTrue($this->store()->push($original), 'Purging the first row frees its key even while the continuation is pending');
        $this->assertSame('b', $this->reserve()?->name);
    }

    /** @return iterable<string,array{EncodedTask}> */
    public static function invalidContinuations(): iterable
    {
        yield 'deduplicated step' => [new EncodedTask('b', 'default', '{}', 'key')];
        yield 'nested chain' => [new EncodedTask('b', 'default', '{}', chain: [new EncodedTask('c', 'default', '{}')])];
        yield 'nul name' => [new EncodedTask("b\0", 'default', '{}')];
        yield 'nul queue' => [new EncodedTask('b', "default\0", '{}')];
        yield 'empty queue' => [new EncodedTask('b', '', '{}')];
        yield 'invalid payload' => [new EncodedTask('b', 'default', '{')];
        yield 'list payload' => [new EncodedTask('b', 'default', '[]')];
    }

    #[DataProvider('invalidContinuations')]
    public function testInvalidContinuationIsRejectedBeforeAnyInsertion(EncodedTask $step): void
    {
        try {
            $this->store()->push(new EncodedTask('a', 'default', '{}', chain: [$step]));
            $this->fail('An invalid continuation must not be stored');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, $this->countTasks());
        }
    }

    public function testMalformedStoredContinuationCanBeReservedButNotCompleted(): void
    {
        $this->store()->push(new EncodedTask('a', 'default', '{}'));
        $this->pdo()->exec('UPDATE ' . self::TABLE . " SET chain = '[{}]'");
        $task = $this->reserve();
        $this->assertNotNull($task);
        $this->assertSame('[{}]', $task->chain);
        try {
            $this->store()->complete($task);
            $this->fail('A malformed continuation must not be discarded on completion');
        } catch (UnexpectedValueException) {
            $this->assertSame('running', $this->row($task->id)['status']);
            $this->assertSame('[{}]', $this->row($task->id)['chain']);
        }
    }

    /** @return iterable<string,array{int}> */
    public static function interruptedTransitions(): iterable
    {
        yield 'after completion update' => [1];
        yield 'after continuation insert' => [2];
    }

    #[DataProvider('interruptedTransitions')]
    public function testInterruptedSequenceTransitionRollsBackBothWrites(int $failAfter): void
    {
        $this->store()->push(new EncodedTask('a', 'default', '{}', chain: [new EncodedTask('b', 'default', '{}')]));
        $a = $this->reserve();
        $this->assertNotNull($a);
        $failure = new RuntimeException('Sequence transition interrupted');
        $real = $this->connection();
        $connection = $this->createStub(TransactionalConnection::class);
        $connection
            ->method('transaction')
            ->willReturnCallback(static function (Closure $callback) use ($real, $failure, $failAfter): mixed {
                return $real->transaction(static fn(Connection $pinned): mixed => $callback(new InterruptedSequenceConnection(
                    $pinned,
                    $failure,
                    $failAfter,
                )));
            });
        try {
            $this->engine()->store($connection, self::TABLE)->complete($a);
            $this->fail('An interrupted completion must propagate');
        } catch (RuntimeException $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertSame(1, $this->countTasks('running'));
        $this->assertSame(1, $this->countTasks());
        $this->assertNotNull($this->row($a->id)['chain']);
        $this->age(61);
        $this->store()->recover();
        $recovered = $this->reserve(owner: 'recovered');
        $this->assertNotNull($recovered);
        $this->assertTrue($this->store()->complete($recovered));
        $this->assertSame('b', $this->reserve()?->name);
    }

    public function testAmbiguousCommitDoesNotCreateASecondContinuation(): void
    {
        $this->store()->push(new EncodedTask('a', 'default', '{}', chain: [new EncodedTask('b', 'default', '{}')]));
        $a = $this->reserve();
        $this->assertNotNull($a);
        $real = $this->connection();
        $failure = new RuntimeException('Commit acknowledgement lost');
        $connection = $this->createStub(TransactionalConnection::class);
        $connection
            ->method('transaction')
            ->willReturnCallback(static function (Closure $callback) use ($real, $failure): never {
                $real->transaction(static fn(Connection $pinned): mixed => $callback($pinned));
                throw $failure;
            });
        try {
            $this->engine()->store($connection, self::TABLE)->complete($a);
            $this->fail('The ambiguous commit must propagate');
        } catch (RuntimeException $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertFalse($this->store()->complete($a));
        $this->assertSame(2, $this->countTasks());
        $this->assertNull($this->row($a->id)['chain']);
        $this->assertSame('b', $this->reserve()?->name);
        $this->assertNull($this->reserve());
    }

    public function testSequenceCompletionRefusesAnExistingTransaction(): void
    {
        $this->store()->push(new EncodedTask('a', 'default', '{}', chain: [new EncodedTask('b', 'default', '{}')]));
        $a = $this->reserve();
        $this->assertNotNull($a);
        $connection = $this->connection();
        $connection->transaction(function (Connection $pinned) use ($connection, $a): void {
            $this->expectException(LogicException::class);
            $this->engine()->store($connection, self::TABLE)->complete($a);
        });
    }

    public function testAddingTheChainColumnPreservesExistingTasks(): void
    {
        $schema = $this->store()->schema();
        if (preg_match('/\bchain ([A-Z]+)/', $schema[0], $matches) !== 1) {
            throw new LogicException('The schema must declare its continuation column');
        }
        $this->pdo()->exec('DROP TABLE ' . self::TABLE);
        foreach ($schema as $statement) {
            $old = preg_replace('/^\h*chain [A-Z]+,\R/m', '', $statement);
            $this->assertNotNull($old);
            $this->pdo()->exec($old);
        }
        $this->pdo()->exec('INSERT INTO ' . self::TABLE . " (name, queue, payload) VALUES ('old', 'default', '{\"id\":42}')");
        $this->pdo()->exec('ALTER TABLE ' . self::TABLE . ' ADD COLUMN chain ' . $matches[1]);
        $old = $this->reserve();
        $this->assertNotNull($old);
        $this->assertSame(['old', '{"id":42}', null], [$old->name, $old->payload, $old->chain]);
        $this->assertTrue($this->store()->complete($old));
        $this->store()->push(new EncodedTask('a', 'default', '{}', chain: [new EncodedTask('b', 'default', '{}')]));
        $a = $this->reserve();
        $this->assertNotNull($a);
        $this->assertTrue($this->store()->complete($a));
        $this->assertSame('b', $this->reserve()?->name);
    }
}
