<?php

declare(strict_types=1);

namespace Kaly\Tasks;

use Closure;
use Kaly\Di\Injector;
use Kaly\Tasks\Store\ReservedTask;
use Kaly\Tasks\Store\TaskStore;
use LogicException;
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
 *
 * A task fails at once with PermanentFailure, and RetryLater chooses its next
 * delay. Any other exception retries with the task's backoff.
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

    /**
     * @param null|Closure(TaskRun,Closure():void):void $around Wraps each task
     *  invocation, to set up and clean a log context, a trace span or a tenant.
     *  It must call its second argument exactly once and let its exceptions
     *  through: any exception it throws counts as a failure of the task.
     */
    public function __construct(
        private readonly TaskStore $store,
        private readonly TaskRegistry $registry,
        private readonly Injector $injector,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?Closure $around = null,
    ) {}

    /**
     * @return int The number of tasks processed
     */
    public function run(WorkerOptions $options = new WorkerOptions()): int
    {
        $this->stopping = false;
        $signals = $this->listenToSignals($options);
        if (!$signals && $options->timeout > 0) {
            $this->log('warning', 'Task timeout of {timeout}s is not enforced without ext-pcntl: the lease must cover the longest run', [
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
                        $this->log('warning', 'Recovered {count} tasks with an expired lease', ['count' => $count]);
                    }
                    $recovered = microtime(true);
                }

                $task = $this->reserve($options);
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

    /**
     * Strict priority asks each queue in turn, so the store index stays per
     * queue and no store needs to know about priorities.
     */
    private function reserve(WorkerOptions $options): ?ReservedTask
    {
        $owner = bin2hex(random_bytes(16));
        if (!$options->prioritizeQueues) {
            return $this->store->reserve($options->queues, $owner, $options->lease);
        }
        foreach ($options->queues as $queue) {
            $task = $this->store->reserve([$queue], $owner, $options->lease);
            if ($task !== null) {
                return $task;
            }
        }
        return null;
    }

    private function process(ReservedTask $reserved, int $timeout): void
    {
        $context = [
            'id' => $reserved->id,
            'task' => $reserved->name,
            'queue' => $reserved->queue,
            'attempt' => $reserved->attempts,
        ];
        // An unknown type fails to decode below, whatever its attempts
        $type = $this->registry->type($reserved->name);
        $retry = $type === null ? RetryPolicy::never() : $type->retry;

        // Checked before decoding: a constructor that kills the worker must not run forever
        if ($type !== null && $reserved->attempts > $retry->maxAttempts) {
            // Previous attempts never settled: their worker died or exceeded its lease
            $this->log('error', 'Task {task} #{id} exceeded {max} attempts', $context + ['max' => $retry->maxAttempts]);
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
                $this->log('error', 'Task {task} #{id} cannot be decoded: {error}', $context + ['error' => $e->getMessage()]);
                $this->settled($this->store->fail($reserved, $e->getMessage()), $context);
                return;
            }
            try {
                $this->invoke($reserved, $task, $retry);
            } catch (Throwable $e) {
                $error = $e::class . ': ' . $e->getMessage();
                if ($e instanceof PermanentFailure || $reserved->attempts >= $retry->maxAttempts) {
                    $this->log('error', 'Task {task} #{id} failed: {error}', $context + ['error' => $error, 'exception' => $e]);
                    $this->settled($this->store->fail($reserved, $error), $context);
                } else {
                    $delay = $e instanceof RetryLater ? $e->seconds : $retry->delayAfter($reserved->attempts);
                    $this->log(
                        'warning',
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

    private function invoke(ReservedTask $reserved, object $task, RetryPolicy $retry): void
    {
        // Registration guarantees __invoke()
        assert(is_callable($task));
        if ($this->around === null) {
            $this->injector->invoke($task);
            return;
        }

        $invoked = false;
        $next = function () use ($task, &$invoked): void {
            if ($invoked) {
                throw new LogicException('A task runs once per attempt');
            }
            $invoked = true;
            $this->injector->invoke($task);
        };
        $run = new TaskRun($reserved->id, $reserved->name, $reserved->queue, $reserved->attempts, $retry->maxAttempts, $task);
        ($this->around)($run, $next);
        if (!$invoked) {
            throw new LogicException('The execution wrapper did not run the task');
        }
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
            $this->log('warning', 'Task {task} #{id} was settled after losing its lease and may run twice', $context);
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        try {
            $this->logger->log($level, $message, $context);
        } catch (Throwable) {
            // Observability must never change a task outcome
            return;
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
                $this->log('critical', 'Task {task} #{id} exceeded {timeout}s, the worker exits', [
                    'id' => $task?->id,
                    'task' => $task?->name,
                    'queue' => $task?->queue,
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
