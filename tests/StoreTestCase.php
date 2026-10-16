<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Kaly\Tasks\Connection\Connection;
use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\Store\TaskStore;
use Kaly\Tasks\Tests\Engine\Engine;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * A test against a real database, given by the engine trait of the concrete
 * class: OnPostgres or OnSqlite.
 */
abstract class StoreTestCase extends TestCase
{
    protected const TABLE = 'kaly_tasks_test';

    protected Engine $engine;

    protected PDO $pdo;

    protected TaskStore $store;

    /**
     * Skips the test when the engine is unavailable.
     */
    abstract protected function createEngine(): Engine;

    protected function setUp(): void
    {
        $this->engine = $this->createEngine();
        $this->pdo = $this->engine->connect();
        $this->engine->reset($this->pdo, self::TABLE);
        $this->store = $this->engine->store($this->connection(), self::TABLE);
    }

    protected function tearDown(): void
    {
        // Releases the handles before deleting a SQLite file
        unset($this->store, $this->pdo);
        gc_collect_cycles();
        if (isset($this->engine)) {
            $this->engine->cleanUp();
        }
    }

    /**
     * The connection under test for the store.
     */
    protected function connection(): Connection
    {
        return new PdoConnection($this->pdo);
    }

    protected function age(int $seconds): void
    {
        $this->engine->age($this->pdo, self::TABLE, $seconds);
    }

    /**
     * Runs a worker fixture in a new process.
     *
     * @param list<string> $arguments
     * @return array{resource,array<int,resource>}
     */
    protected function startProcess(string $script, array $arguments = []): array
    {
        $command = [PHP_BINARY, __DIR__ . '/Fixtures/' . $script, ...$this->engine->arguments(), self::TABLE, ...$arguments];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        return [$process, $pipes];
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
