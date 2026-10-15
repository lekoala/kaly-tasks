<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\DatabaseTaskDispatcher;
use Kaly\Tasks\TaskRegistry;
use Kaly\Tasks\Tests\Fixtures\LogTask;

/**
 * Several worker processes drain one table: every task runs exactly once when
 * no worker crashes.
 */
class ConcurrencyTest extends PostgresTestCase
{
    private const TASKS = 300;
    private const WORKERS = 4;

    public function testWorkersNeverRunATaskTwice(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS kaly_tasks_log');
        $this->pdo->exec('CREATE TABLE kaly_tasks_log (number INTEGER NOT NULL, pid INTEGER NOT NULL)');
        $tasks = new DatabaseTaskDispatcher($this->store, (new TaskRegistry())->register('test.log', LogTask::class));
        $this->pdo->beginTransaction();
        for ($i = 1; $i <= self::TASKS; $i++) {
            $tasks->dispatch(new LogTask($i));
        }
        $this->pdo->commit();

        $processes = [];
        for ($i = 0; $i < self::WORKERS; $i++) {
            $command = [PHP_BINARY, __DIR__ . '/Fixtures/worker.php', self::dsn(), self::TABLE];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        $processed = [];
        foreach ($processes as [$process, $pipes]) {
            $output = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            $this->assertSame(0, proc_close($process), $errors);
            $processed[] = (int) $output;
        }

        $log = new PdoConnection($this->pdo);
        $stats = $log->fetchAll(
            'SELECT count(*) AS runs, count(DISTINCT number) AS tasks, count(DISTINCT pid) AS workers FROM kaly_tasks_log',
        )[0];
        $this->assertSame(self::TASKS, array_sum($processed));
        $this->assertSame([self::TASKS, self::TASKS], [$stats['runs'], $stats['tasks']]);
        $this->assertGreaterThan(1, $stats['workers'], 'The work was shared');
        $this->assertSame(self::TASKS, $this->countTasks('done'));
        $this->pdo->exec('DROP TABLE kaly_tasks_log');
    }
}
