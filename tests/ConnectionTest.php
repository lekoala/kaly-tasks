<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Closure;
use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\Postgres\TcpConnectionConfig;
use Cycle\Database\Config\PostgresDriverConfig;
use Cycle\Database\DatabaseManager;
use Doctrine\DBAL\DriverManager;
use Kaly\Tasks\Connection\Connection;
use Kaly\Tasks\Connection\CycleConnection;
use Kaly\Tasks\Connection\DoctrineConnection;
use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\DatabaseTaskDispatcher;
use Kaly\Tasks\Store\PostgresTaskStore;
use Kaly\Tasks\TaskRegistry;
use Kaly\Tasks\Tests\Fixtures\RecordTask;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The outbox guarantee through each supported connection: a task dispatched
 * inside the application transaction commits and rolls back with it.
 */
class ConnectionTest extends PostgresTestCase
{
    /**
     * @return array{connection:Connection,begin:Closure():mixed,commit:Closure():mixed,rollback:Closure():mixed}
     */
    private function viaPdo(): array
    {
        $pdo = new PDO(self::dsn());
        return [
            'connection' => new PdoConnection(static fn(): PDO => $pdo),
            'begin' => $pdo->beginTransaction(...),
            'commit' => $pdo->commit(...),
            'rollback' => $pdo->rollBack(...),
        ];
    }

    /**
     * @return array{connection:Connection,begin:Closure():mixed,commit:Closure():mixed,rollback:Closure():mixed}
     */
    private function viaDoctrine(): array
    {
        $dsn = self::dsnParts();
        $dbal = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => $dsn['host'],
            'port' => (int) $dsn['port'],
            'dbname' => $dsn['dbname'],
            'user' => $dsn['user'],
            'password' => $dsn['password'],
        ]);
        return [
            'connection' => new DoctrineConnection($dbal),
            'begin' => $dbal->beginTransaction(...),
            'commit' => $dbal->commit(...),
            'rollback' => $dbal->rollBack(...),
        ];
    }

    /**
     * @return array{connection:Connection,begin:Closure():mixed,commit:Closure():mixed,rollback:Closure():mixed}
     */
    private function viaCycle(): array
    {
        $dsn = self::dsnParts();
        $manager = new DatabaseManager(new DatabaseConfig([
            'default' => 'default',
            'databases' => ['default' => ['connection' => 'pgsql']],
            'connections' => [
                'pgsql' => new PostgresDriverConfig(new TcpConnectionConfig(
                    database: $dsn['dbname'],
                    host: $dsn['host'],
                    port: $dsn['port'],
                    user: $dsn['user'],
                    password: $dsn['password'],
                )),
            ],
        ]));
        $database = $manager->database();
        return [
            'connection' => new CycleConnection($database),
            'begin' => $database->begin(...),
            'commit' => $database->commit(...),
            'rollback' => $database->rollback(...),
        ];
    }

    /**
     * @return array{host:non-empty-string,port:numeric-string,dbname:non-empty-string,user:non-empty-string,password:non-empty-string}
     */
    private static function dsnParts(): array
    {
        $parts = ['host' => 'localhost', 'port' => '5432', 'dbname' => '', 'user' => '', 'password' => ''];
        foreach (explode(';', substr(self::dsn(), strlen('pgsql:'))) as $pair) {
            [$key, $value] = explode('=', $pair, 2) + [1 => ''];
            $parts[$key] = $value;
        }
        /** @var array{host:non-empty-string,port:numeric-string,dbname:non-empty-string,user:non-empty-string,password:non-empty-string} */
        return $parts;
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function connections(): iterable
    {
        yield 'pdo' => ['pdo'];
        yield 'doctrine' => ['doctrine'];
        yield 'cycle' => ['cycle'];
    }

    #[DataProvider('connections')]
    public function testDispatchJoinsTheApplicationTransaction(string $library): void
    {
        $application = match ($library) {
            'pdo' => $this->viaPdo(),
            'doctrine' => $this->viaDoctrine(),
            default => $this->viaCycle(),
        };
        $store = new PostgresTaskStore($application['connection'], self::TABLE);
        $tasks = new DatabaseTaskDispatcher($store, (new TaskRegistry())->register('test.record', RecordTask::class));

        $application['begin']();
        $tasks->dispatch(new RecordTask('rolled back'));
        $application['rollback']();
        $this->assertSame(0, $this->countTasks());

        $application['begin']();
        $tasks->dispatch(new RecordTask('committed'));
        $this->assertSame(0, $this->countTasks(), 'Invisible to other connections before the commit');
        $application['commit']();
        $this->assertSame(1, $this->countTasks());

        $reserved = $store->reserve(['default'], 'owner', 60);
        $this->assertNotNull($reserved);
        $this->assertSame(['test.record', '{"entry": "committed"}'], [$reserved->name, $reserved->payload]);
        $store->fail($reserved, 'Broken');
        $this->assertSame([$reserved->id], array_column($store->failed(10), 'id'));
    }
}
