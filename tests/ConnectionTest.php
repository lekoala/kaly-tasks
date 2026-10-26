<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\DriverManager;
use Kaly\Tasks\Connection\DoctrineConnection;
use Kaly\Tasks\DatabaseTaskDispatcher;
use Kaly\Tasks\Store\PostgresTaskStore;
use Kaly\Tasks\TaskRegistry;
use Kaly\Tasks\Tests\Engine\OnPostgres;
use Kaly\Tasks\Tests\Fixtures\RecordTask;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The outbox guarantee through each supported connection: a task dispatched
 * inside the application transaction commits and rolls back with it.
 */
class ConnectionTest extends StoreTestCase
{
    use OnPostgres;

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
            'pdo' => $this->postgres()->viaPdo(),
            'doctrine' => $this->postgres()->viaDoctrine(),
            default => $this->postgres()->viaCycle(),
        };
        $store = new PostgresTaskStore($application['connection'], self::TABLE);
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

    public function testDoctrineReservesOnThePrimary(): void
    {
        $params = $this->postgres()->doctrineParams();
        $dbal = DriverManager::getConnection([
            'wrapperClass' => PrimaryReadReplicaConnection::class,
            'driver' => 'pdo_pgsql',
            'primary' => $params,
            'replica' => [$params],
            'keepReplica' => true,
        ]);
        $this->assertInstanceOf(PrimaryReadReplicaConnection::class, $dbal);
        $dbal->ensureConnectedToReplica();

        $store = new PostgresTaskStore(new DoctrineConnection($dbal), self::TABLE);
        $this->assertNull($store->reserve(['default'], 'owner', 60));

        $this->assertTrue($dbal->isConnectedToPrimary());
    }
}
