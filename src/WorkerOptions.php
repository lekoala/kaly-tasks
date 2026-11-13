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
     * @param list<string> $queues Queues to poll. Their order is a priority only
     *  with `prioritizeQueues`; otherwise each store applies its own selection.
     * @param int $lease How long a reserved task belongs to this worker
     * @param int $timeout Hard limit of one task: the worker process exits when
     *  it is exceeded (requires ext-pcntl, 0 disables). Must be lower than the lease.
     * @param int $maxTasks Exit after this many tasks
     * @param int $maxRuntime Exit after this many seconds
     * @param int $memoryLimit Exit once memory usage reaches this many bytes
     * @param int $sleepMs Pause when no task is available, in milliseconds
     * @param int $recoverInterval How often expired leases are recovered
     * @param bool $stopWhenEmpty Exit as soon as no task is available
     * @param bool $prioritizeQueues Reserve from each queue in order and take the
     *  first available task: earlier queues have strict priority and can starve
     *  later ones. Costs one reservation call per queue polled.
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
        public bool $prioritizeQueues = false,
    ) {
        if ($queues === []) {
            throw new InvalidArgumentException('A worker needs at least one queue');
        }
        if (in_array('', $queues, true)) {
            throw new InvalidArgumentException('Worker queue names cannot be empty');
        }
        if ($lease < 1 || $lease > 2_147_483_647) {
            throw new InvalidArgumentException('The lease must be between 1 and 2147483647 seconds');
        }
        if ($timeout < 0 || $timeout > 0 && $timeout >= $lease) {
            throw new InvalidArgumentException('The task timeout must be shorter than the lease');
        }
        if ($maxTasks < 1 || $maxRuntime < 1 || $memoryLimit < 1 || $sleepMs < 0 || $recoverInterval < 0) {
            throw new InvalidArgumentException('Worker limits must be positive');
        }
    }
}
