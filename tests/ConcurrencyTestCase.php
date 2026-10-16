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
abstract class ConcurrencyTestCase extends StoreTestCase
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
        // A database clock stepping back (a VM resync) would make the tasks
        // not due yet, and the workers would stop early
        $this->age(60);

        $processed = $this->runWorkers();

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

    /**
     * Starts the workers together at a barrier: a fast first worker cannot
     * drain the table before the others have started.
     *
     * @return list<int> The tasks processed by each worker
     */
    private function runWorkers(): array
    {
        $barrier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-tasks-barrier-' . bin2hex(random_bytes(8));
        mkdir($barrier);
        try {
            $processes = [];
            for ($i = 0; $i < self::WORKERS; $i++) {
                $processes[] = $this->startProcess('worker.php', [$barrier]);
            }
            $deadline = microtime(true) + 30;
            while (count(glob($barrier . '/ready-*') ?: []) < self::WORKERS) {
                if (microtime(true) > $deadline) {
                    $this->fail('The workers did not start');
                }
                usleep(10_000);
            }
            touch($barrier . '/go');

            $processed = [];
            foreach ($processes as [$process, $pipes]) {
                $output = (string) stream_get_contents($pipes[1]);
                $errors = (string) stream_get_contents($pipes[2]);
                $this->assertSame(0, proc_close($process), $errors);
                $this->assertMatchesRegularExpression('/^\d+$/', $output, 'A worker prints only its count');
                $processed[] = (int) $output;
            }
            return $processed;
        } finally {
            array_map(unlink(...), glob($barrier . '/*') ?: []);
            rmdir($barrier);
        }
    }
}
