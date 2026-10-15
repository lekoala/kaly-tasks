<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

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
        $this->store = new PostgresTaskStore(new PdoConnection($this->pdo), self::TABLE);
        $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        foreach ($this->store->schema() as $statement) {
            $this->pdo->exec($statement);
        }
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
