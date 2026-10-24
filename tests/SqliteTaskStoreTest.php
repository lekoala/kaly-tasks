<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use InvalidArgumentException;
use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\EncodedTask;
use Kaly\Tasks\Store\SqliteTaskStore;
use Kaly\Tasks\Tests\Engine\OnSqlite;

class SqliteTaskStoreTest extends TaskStoreTestCase
{
    use OnSqlite;

    public function testRejectsUnsafeTableNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SqliteTaskStore(new PdoConnection($this->pdo()), 'main.tasks');
    }

    public function testReservationsFromSeveralConnectionsNeverOverlap(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->store()->push(new EncodedTask('a', 'default', '{}'));
        }
        $other = new SqliteTaskStore(new PdoConnection($this->engine()->connect()), self::TABLE);

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->store()->reserve(['default'], 'owner-a', 60)?->id;
            $ids[] = $other->reserve(['default'], 'owner-b', 60)?->id;
        }

        $this->assertSame(range(1, 10), $ids);
        $this->assertNull($other->reserve(['default'], 'owner-b', 60));
    }

    public function testDispatchJoinsTheApplicationTransaction(): void
    {
        $application = $this->engine()->connect();
        $store = new SqliteTaskStore(new PdoConnection($application), self::TABLE);

        $application->beginTransaction();
        $store->push(new EncodedTask('a', 'default', '{}'));
        $application->rollBack();
        $this->assertSame(0, $this->countTasks());

        $application->beginTransaction();
        $store->push(new EncodedTask('a', 'default', '{}'));
        $this->assertSame(0, $this->countTasks(), 'Invisible to other connections before the commit');
        $application->commit();
        $this->assertSame(1, $this->countTasks());
    }

    public function testPurgedIdsAreNotReused(): void
    {
        $this->store()->push(new EncodedTask('a', 'default', '{}'));
        $task = $this->store()->reserve(['default'], 'owner', 60);
        $this->assertNotNull($task);
        $this->store()->complete($task);
        $this->age(120);
        $this->assertSame(1, $this->store()->purge(60));

        $this->store()->push(new EncodedTask('b', 'default', '{}'));

        $this->assertSame(2, $this->store()->reserve(['default'], 'owner', 60)?->id);
    }
}
