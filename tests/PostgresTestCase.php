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
use Kaly\Tasks\Store\PostgresTaskStore;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests against a real PostgreSQL: KALY_TASKS_PGSQL_DSN, see compose.yaml.
 */
abstract class PostgresTestCase extends TestCase
{
    protected const TABLE = 'kaly_tasks_test';

    protected PDO $pdo;

    protected PostgresTaskStore $store;

    protected function setUp(): void
    {
        $dsn = self::dsn();
        if ($dsn === '') {
            $this->markTestSkipped('KALY_TASKS_PGSQL_DSN is empty');
        }
        $this->pdo = new PDO($dsn);
        $this->store = new PostgresTaskStore($this->connection(), self::TABLE);
        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        foreach ($this->store->schema() as $statement) {
            $this->pdo->exec($statement);
        }
    }

    /**
     * The connection under test for the store.
     */
    protected function connection(): Connection
    {
        return new PdoConnection($this->pdo);
    }

    /**
     * @return array{connection:Connection,begin:Closure():mixed,commit:Closure():mixed,rollback:Closure():mixed}
     */
    protected function viaPdo(): array
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
    protected function viaDoctrine(): array
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
    protected function viaCycle(): array
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
    protected static function dsnParts(): array
    {
        $parts = ['host' => 'localhost', 'port' => '5432', 'dbname' => '', 'user' => '', 'password' => ''];
        foreach (explode(';', substr(self::dsn(), strlen('pgsql:'))) as $pair) {
            [$key, $value] = explode('=', $pair, 2) + [1 => ''];
            $parts[$key] = $value;
        }
        /** @var array{host:non-empty-string,port:numeric-string,dbname:non-empty-string,user:non-empty-string,password:non-empty-string} */
        return $parts;
    }

    protected static function dsn(): string
    {
        $dsn = getenv('KALY_TASKS_PGSQL_DSN');
        return is_string($dsn) ? $dsn : '';
    }

    /**
     * Moves every running lease and pending availability into the past.
     */
    protected function age(int $seconds): void
    {
        $this->pdo->exec(sprintf(
            "UPDATE %s SET lease_until = lease_until - INTERVAL '%d seconds', available_at = available_at - INTERVAL '%d seconds',
            finished_at = finished_at - INTERVAL '%d seconds'",
            self::TABLE,
            $seconds,
            $seconds,
            $seconds,
        ));
    }

    /**
     * @return array<string,mixed>
     */
    protected function row(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ?');
        $statement->execute([$id]);
        /** @var array<string,mixed> */
        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    protected function countTasks(?string $status = null): int
    {
        $statement = $this->pdo->prepare('SELECT count(*) FROM ' . self::TABLE . ($status === null ? '' : ' WHERE status = ?'));
        $statement->execute($status === null ? [] : [$status]);
        $count = $statement->fetchColumn();
        if (!is_int($count)) {
            $this->fail('count(*) returned ' . get_debug_type($count));
        }
        return $count;
    }
}
