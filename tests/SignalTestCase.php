<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Kaly\Tasks\DatabaseTaskDispatcher;
use Kaly\Tasks\TaskRegistry;
use Kaly\Tasks\Tests\Fixtures\SleepTask;

/**
 * Signals and hard timeouts, in a real worker process. Requires ext-pcntl.
 */
abstract class SignalTestCase extends StoreTestCase
{
    protected function setUp(): void
    {
        // An attribute on this abstract class would not reach the engine classes
        if (!function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('Requires ext-pcntl');
        }
        parent::setUp();
    }

    /**
     * @return array{resource,array<int,resource>}
     */
    private function startWorker(int $timeout): array
    {
        return $this->startProcess('signal-worker.php', [(string) $timeout]);
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
