<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Kaly\Tasks\DatabaseTaskDispatcher;
use Kaly\Tasks\TaskRegistry;
use Kaly\Tasks\Tests\Fixtures\SleepTask;
use PHPUnit\Framework\Attributes\RequiresFunction;

/**
 * Signals and hard timeouts, in a real worker process. Requires ext-pcntl.
 */
#[RequiresFunction('pcntl_async_signals')]
class SignalTest extends PostgresTestCase
{
    /**
     * @return array{resource,array<int,resource>}
     */
    private function startWorker(int $timeout): array
    {
        $command = [PHP_BINARY, __DIR__ . '/Fixtures/signal-worker.php', self::dsn(), self::TABLE, (string) $timeout];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        return [$process, $pipes];
    }

    private function dispatch(int ...$seconds): void
    {
        $tasks = new DatabaseTaskDispatcher($this->store, (new TaskRegistry())->register('test.sleep', SleepTask::class));
        foreach ($seconds as $duration) {
            $tasks->dispatch(new SleepTask($duration));
        }
    }

    private function waitForRunningTask(): void
    {
        $deadline = microtime(true) + 10;
        while ($this->countTasks('running') === 0) {
            if (microtime(true) > $deadline) {
                $this->fail('No task started running');
            }
            usleep(50_000);
        }
    }

    public function testSigtermFinishesTheCurrentTaskThenStops(): void
    {
        $this->dispatch(5, 5);
        [$process, $pipes] = $this->startWorker(timeout: 30);
        $this->waitForRunningTask();

        proc_terminate($process, 15);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);

        $this->assertSame(0, proc_close($process), $errors);
        $this->assertSame('1', $output);
        $this->assertSame([1, 1], [$this->countTasks('done'), $this->countTasks('pending')]);
    }

    public function testTimeoutEndsTheProcessAndLeavesTheLease(): void
    {
        $this->dispatch(20);
        $started = microtime(true);
        [$process, $pipes] = $this->startWorker(timeout: 1);

        $errors = (string) stream_get_contents($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(1, $exitCode, $errors);
        $this->assertLessThan(10, microtime(true) - $started);
        $this->assertSame(1, $this->countTasks('running'), 'Recovered by another worker once the lease expires');
    }
}
