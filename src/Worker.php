<?php

declare(strict_types=1);

namespace Kaly\Tasks;

use Kaly\Di\Injector;
use Kaly\Tasks\Store\ReservedTask;
use Kaly\Tasks\Store\TaskStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Runs stored tasks, one at a time, in a supervised process.
 *
 * The worker is crash-only: a store failure propagates and ends the process,
 * leases expire and another worker recovers the tasks. It exits cleanly after
 * `maxTasks`, `maxRuntime` or `memoryLimit` so the supervisor (systemd,
 * supervisord, a container restart policy) starts a fresh process.
 *
 * Task services come from the container through the Injector. Shared services
 * must be re-entrant: the worker opens no scope per task.
 *
 * With ext-pcntl, SIGTERM, SIGINT and SIGQUIT finish the current task then
 * stop, and a task running beyond `timeout` ends the process.
 */
final class Worker
{
    private bool $stopping = false;

    private ?ReservedTask $current = null;

    /**
     * @var array<int,mixed> Host handlers replaced while running, by signal
     */
    private array $previousHandlers = [];

    private bool $previousAsyncSignals = false;

    public function __construct(
        private readonly TaskStore $store,
        private readonly TaskRegistry $registry,
        private readonly Injector $injector,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @return int The number of tasks processed
     */
    public function run(WorkerOptions $options = new WorkerOptions()): int
    {
        $this->stopping = false;
        $signals = $this->listenToSignals($options);
        if (!$signals && $options->timeout > 0) {
            $this->logger->warning('Task timeout of {timeout}s is not enforced without ext-pcntl: the lease must cover the longest run', [
                'timeout' => $options->timeout,
            ]);
        }
        $started = microtime(true);
        $recovered = null;
        $processed = 0;

        try {
            while (!$this->stopping) {
                if ($recovered === null || (microtime(true) - $recovered) >= $options->recoverInterval) {
                    $count = $this->store->recover();
                    if ($count > 0) {
                        $this->logger->warning('Recovered {count} tasks with an expired lease', ['count' => $count]);
                    }
                    $recovered = microtime(true);
                }

                $task = $this->store->reserve($options->queues, bin2hex(random_bytes(16)), $options->lease);
                if ($task === null) {
                    if ($options->stopWhenEmpty) {
                        break;
                    }
                    $this->sleep($options->sleepMs);
                } else {
                    $this->process($task, $signals ? $options->timeout : 0);
                    $processed++;
                }

                if (
                    $processed >= $options->maxTasks
                    || (microtime(true) - $started) >= $options->maxRuntime
                    || memory_get_usage(true) >= $options->memoryLimit
                ) {
                    break;
                }
            }
        } finally {
            if ($signals) {
                $this->restoreSignals();
            }
        }

        return $processed;
    }

    /**
     * Finishes the current task, then stops.
     */
    public function stop(): void
    {
        $this->stopping = true;
    }

    private function process(ReservedTask $reserved, int $timeout): void
    {
        $context = ['id' => $reserved->id, 'task' => $reserved->name, 'attempt' => $reserved->attempts];
        // An unknown type fails to decode below, whatever its attempts
        $type = $this->registry->type($reserved->name);
        $retry = $type === null ? RetryPolicy::never() : $type->retry;

        // Checked before decoding: a constructor that kills the worker must not run forever
        if ($type !== null && $reserved->attempts > $retry->maxAttempts) {
            // Previous attempts never settled: their worker died or exceeded its lease
            $this->logger->error('Task {task} #{id} exceeded {max} attempts', $context + ['max' => $retry->maxAttempts]);
            $this->settled($this->store->fail($reserved, "Exceeded {$retry->maxAttempts} attempts"), $context);
            return;
        }

        $this->current = $reserved;
        if ($timeout > 0) {
            pcntl_alarm($timeout);
        }
        try {
            // The constructor runs under the timeout too
            try {
                $task = $this->registry->decode($reserved->name, $reserved->payload);
            } catch (Throwable $e) {
                $this->logger->error('Task {task} #{id} cannot be decoded: {error}', $context + ['error' => $e->getMessage()]);
                $this->settled($this->store->fail($reserved, $e->getMessage()), $context);
                return;
            }
            try {
                // Registration guarantees __invoke()
                assert(is_callable($task));
                $this->injector->invoke($task);
            } catch (Throwable $e) {
                $error = $e::class . ': ' . $e->getMessage();
                if ($reserved->attempts >= $retry->maxAttempts) {
                    $this->logger->error('Task {task} #{id} failed: {error}', $context + ['error' => $error, 'exception' => $e]);
                    $this->settled($this->store->fail($reserved, $error), $context);
                } else {
                    $delay = $retry->delayAfter($reserved->attempts);
                    $this->logger->warning(
                        'Task {task} #{id} will retry in {delay}s: {error}',
                        $context + ['delay' => $delay, 'error' => $error, 'exception' => $e],
                    );
                    $this->settled($this->store->release($reserved, $delay, $error), $context);
                }
                return;
            }
        } finally {
            if ($timeout > 0) {
                pcntl_alarm(0);
            }
            $this->current = null;
        }

        $this->settled($this->store->complete($reserved), $context);
    }

    /**
     * A task settled after its lease expired belongs to another worker: its
     * outcome (and retry delay) is lost, and it may run again.
     *
     * @param array<string,mixed> $context
     */
    private function settled(bool $settled, array $context): void
    {
        if (!$settled) {
            $this->logger->warning('Task {task} #{id} was settled after losing its lease and may run twice', $context);
        }
    }

    private function sleep(int $milliseconds): void
    {
        // Short naps keep the worker responsive to stop()
        $until = microtime(true) + ($milliseconds / 1000);
        while (!$this->stopping && microtime(true) < $until) {
            usleep(min(100_000, max(0, (int) (($until - microtime(true)) * 1_000_000))));
        }
    }

    private function listenToSignals(WorkerOptions $options): bool
    {
        if (!function_exists('pcntl_async_signals')) {
            return false;
        }
        $this->previousAsyncSignals = pcntl_async_signals(true);
        $this->previousHandlers = [];
        $handlers = [SIGTERM => $this->stop(...), SIGINT => $this->stop(...), SIGQUIT => $this->stop(...)];
        if ($options->timeout > 0) {
            $handlers[SIGALRM] = function () use ($options): void {
                $task = $this->current;
                $this->logger->critical('Task {task} #{id} exceeded {timeout}s, the worker exits', [
                    'id' => $task?->id,
                    'task' => $task?->name,
                    'timeout' => $options->timeout,
                ]);
                // Deliberately skips the task settlement: the lease expires and
                // another worker recovers the task
                exit(1);
            };
        }
        foreach ($handlers as $signal => $handler) {
            $this->previousHandlers[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, $handler);
        }
        return true;
    }

    /**
     * Gives the host process its own signal handling back.
     */
    private function restoreSignals(): void
    {
        foreach ($this->previousHandlers as $signal => $handler) {
            pcntl_signal($signal, is_callable($handler) || is_int($handler) ? $handler : SIG_DFL);
        }
        $this->previousHandlers = [];
        pcntl_async_signals($this->previousAsyncSignals);
    }
}
