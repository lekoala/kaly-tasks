<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Closure;
use Kaly\Tasks\Connection\Connection;
use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\Connection\TransactionalConnection;
use Kaly\Tasks\EncodedTask;
use Kaly\Tasks\Store\MysqlTaskStore;
use Kaly\Tasks\Store\ReservedTask;
use Kaly\Tasks\Tests\Engine\OnMysql;

final class MysqlMultiQueueReservationTest extends StoreTestCase
{
    use OnMysql;

    public function testWorkersReserveDifferentTasksAcrossTwoQueuesBeforeTheFirstCommit(): void
    {
        foreach (['alpha', 'beta'] as $queue) {
            for ($number = 0; $number < 3; $number++) {
                $this->store()->push(new EncodedTask($queue . $number, $queue, '{}'));
            }
        }
        $this->age(60);
        $other = $this->engine()->connect();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $otherStore = new MysqlTaskStore(new PdoConnection($other), self::TABLE);
        $probe = new OverlappingReservationConnection(new PdoConnection($this->pdo()), function (?ReservedTask $first) use (
            $otherStore,
        ): void {
            $this->assertNotNull($first);
            $second = $otherStore->reserve(['alpha', 'beta'], 'second', 60);
            $this->assertNotNull($second, 'A reservation must leave other ready tasks available before committing');
            $this->assertNotSame($first->id, $second->id);
        });
        $first = (new MysqlTaskStore($probe, self::TABLE))->reserve(['alpha', 'beta'], 'first', 60);
        $this->assertNotNull($first);
        $this->assertSame(2, $this->countTasks('running'));
        $this->assertSame(4, $this->countTasks('pending'));

        foreach ($probe->queries as $query) {
            $plan = (new PdoConnection($this->pdo()))->fetchAll('EXPLAIN ' . $query['sql'], $query['params']);
            $this->assertSame('pending', $plan[0]['key']);
            $extra = $plan[0]['Extra'];
            $this->assertIsString($extra);
            $this->assertStringNotContainsString('filesort', $extra);
        }
        $this->assertNotEmpty($probe->queries);
    }
}

/** Runs a second worker while the first still holds its reservation locks. */
final class OverlappingReservationConnection implements TransactionalConnection
{
    /** @var list<array{sql:non-empty-string,params:list<int|string|null>}> */
    public array $queries = [];

    /** @param Closure(?ReservedTask):void $beforeCommit */
    public function __construct(
        private readonly PdoConnection $connection,
        private readonly Closure $beforeCommit,
    ) {}

    /**
     * @template T
     * @param Closure(Connection):T $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        return $this->connection->transaction(function (Connection $pinned) use ($callback): mixed {
            $result = $callback($this);
            if ($result instanceof ReservedTask || $result === null) {
                ($this->beforeCommit)($result);
            }
            return $result;
        });
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];
        return $this->connection->fetchAll($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->connection->execute($sql, $params);
    }
}
