<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\DriverManager;
use Kaly\Tasks\Connection\Connection;
use Kaly\Tasks\Connection\DoctrineConnection;
use Kaly\Tasks\DatabaseTaskDispatcher;
use Kaly\Tasks\Store\MysqlTaskStore;
use Kaly\Tasks\TaskRegistry;
use Kaly\Tasks\Tests\Engine\OnMysql;
use Kaly\Tasks\Tests\Fixtures\RecordTask;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The outbox guarantee through each supported connection: a task dispatched
 * inside the application transaction commits and rolls back with it.
 */
class MysqlConnectionTest extends StoreTestCase
{
    use OnMysql;

    /**
     * @return iterable<string,array{string}>
     */
    public static function connections(): iterable
    {
        yield 'pdo' => ['pdo'];
        yield 'doctrine' => ['doctrine'];
        yield 'cycle' => ['cycle'];
    }

    /** @return iterable<string,array{string,bool}> */
    public static function dispatches(): iterable
    {
        foreach (['pdo', 'doctrine', 'cycle'] as $library) {
            yield $library . ' immediate' => [$library, false];
            yield $library . ' scheduled' => [$library, true];
        }
    }

    #[DataProvider('dispatches')]
    public function testDispatchJoinsTheApplicationTransaction(string $library, bool $scheduled): void
    {
        $application = match ($library) {
            'pdo' => $this->mysql()->viaPdo(),
            'doctrine' => $this->mysql()->viaDoctrine(),
            default => $this->mysql()->viaCycle(),
        };
        $store = new MysqlTaskStore($application['connection'], self::TABLE);
        $tasks = new DatabaseTaskDispatcher($store, (new TaskRegistry())->register('test.record', RecordTask::class));

        $notBefore = $scheduled ? new DateTimeImmutable('+1 hour') : null;
        $application['begin']();
        $tasks->dispatch(new RecordTask('rolled back'), notBefore: $notBefore);
        $application['rollback']();
        $this->assertSame(0, $this->countTasks());

        $application['begin']();
        $tasks->dispatch(new RecordTask('committed'), notBefore: $notBefore);
        $this->assertSame(0, $this->countTasks(), 'Invisible to other connections before the commit');
        $application['commit']();
        $this->assertSame(1, $this->countTasks());

        if ($scheduled) {
            $this->assertNull($store->reserve(['default'], 'owner', 60));
            $this->age(3610);
        }
        $reserved = $store->reserve(['default'], 'owner', 60);
        $this->assertNotNull($reserved);
        $this->assertSame(['test.record', '{"entry":"committed"}'], [$reserved->name, $reserved->payload]);
        $store->fail($reserved, 'Broken');
        $this->assertSame([$reserved->id], array_column($store->failed(10), 'id'));
    }

    #[DataProvider('connections')]
    public function testReservationRefusesAnApplicationTransaction(string $library): void
    {
        $application = match ($library) {
            'pdo' => $this->mysql()->viaPdo(),
            'doctrine' => $this->mysql()->viaDoctrine(),
            default => $this->mysql()->viaCycle(),
        };
        $application['begin']();
        try {
            $store = new MysqlTaskStore($application['connection'], self::TABLE);
            $this->expectException(LogicException::class);
            $store->reserve(['default'], 'owner', 60);
        } finally {
            $application['rollback']();
        }
    }

    #[DataProvider('connections')]
    public function testTransactionRollsBackAndPreservesTheFailure(string $library): void
    {
        $application = match ($library) {
            'pdo' => $this->mysql()->viaPdo(),
            'doctrine' => $this->mysql()->viaDoctrine(),
            default => $this->mysql()->viaCycle(),
        };
        $failure = new RuntimeException('Reservation interrupted');
        try {
            $application['connection']->transaction(function (Connection $connection) use ($failure): void {
                $connection->execute('INSERT INTO ' . self::TABLE . " (queue, name, payload) VALUES ('default', 'a', '{}')");
                throw $failure;
            });
        } catch (RuntimeException $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertSame(0, $this->countTasks());
        $this->assertSame(42, $application['connection']->transaction(static fn(Connection $connection): int => 42));
    }

    public function testDoctrineReservesOnThePrimary(): void
    {
        $params = $this->mysql()->doctrineParams();
        $dbal = DriverManager::getConnection([
            'wrapperClass' => PrimaryReadReplicaConnection::class,
            'driver' => 'pdo_mysql',
            'primary' => $params,
            'replica' => [$params],
            'keepReplica' => true,
        ]);
        $this->assertInstanceOf(PrimaryReadReplicaConnection::class, $dbal);
        $dbal->ensureConnectedToReplica();

        $store = new MysqlTaskStore(new DoctrineConnection($dbal), self::TABLE);
        $this->assertNull($store->reserve(['default'], 'owner', 60));

        $this->assertTrue($dbal->isConnectedToPrimary());
    }
}
