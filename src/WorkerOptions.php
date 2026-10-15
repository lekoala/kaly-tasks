<?php

declare(strict_types=1);

namespace Kaly\Tasks;

use InvalidArgumentException;

/**
 * How a worker polls, leases and recycles itself. Durations are in seconds.
 */
final readonly class WorkerOptions
{
    /**
     * @param list<string> $queues Queues to poll, in no priority order
     * @param int $lease How long a reserved task belongs to this worker
     * @param int $timeout Hard limit of one task: the worker process exits when
     *  it is exceeded (requires ext-pcntl, 0 disables). Must be lower than the lease.
     * @param int $maxTasks Exit after this many tasks
     * @param int $maxRuntime Exit after this many seconds
     * @param int $memoryLimit Exit once memory usage reaches this many bytes
     * @param int $sleepMs Pause when no task is available, in milliseconds
     * @param int $recoverInterval How often expired leases are recovered
     * @param bool $stopWhenEmpty Exit as soon as no task is available
     */
    public function __construct(
        public array $queues = ['default'],
        public int $lease = 300,
        public int $timeout = 240,
        public int $maxTasks = 1000,
        public int $maxRuntime = 3600,
        public int $memoryLimit = 128 * 1024 * 1024,
        public int $sleepMs = 1000,
        public int $recoverInterval = 60,
        public bool $stopWhenEmpty = false,
    ) {
        if ($queues === []) {
            throw new InvalidArgumentException('A worker needs at least one queue');
        }
        if ($lease < 1) {
            throw new InvalidArgumentException('The lease must last at least one second');
        }
        if ($timeout < 0 || $timeout > 0 && $timeout >= $lease) {
            throw new InvalidArgumentException('The task timeout must be shorter than the lease');
        }
        if ($maxTasks < 1 || $maxRuntime < 1 || $memoryLimit < 1 || $sleepMs < 0 || $recoverInterval < 0) {
            throw new InvalidArgumentException('Worker limits must be positive');
        }
    }
}
