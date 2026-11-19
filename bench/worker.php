<?php

declare(strict_types=1);

/**
 * Real Worker process, with reservation and statement counters.
 *
 * php worker.php <poll|notify> <seconds> <sleepMs> <fallbackMs> <latencyFile> <statsFile>
 */

use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\Connection\TransactionalConnection;
use Kaly\Tasks\EncodedTask;
use Kaly\Tasks\Store\PostgresNotifyWaiter;
use Kaly\Tasks\Store\ReservedTask;
use Kaly\Tasks\Store\TaskStats;
use Kaly\Tasks\Store\TaskStore;
use Kaly\Tasks\Worker;
use Kaly\Tasks\WorkerOptions;

require __DIR__ . '/bootstrap.php';

$arguments = is_array($_SERVER['argv'] ?? null) ? array_values(array_filter($_SERVER['argv'], is_string(...))) : [];
if (count($arguments) !== 7) {
    fwrite(STDERR, 'Usage: php worker.php <poll|notify> <seconds> <sleepMs> <fallbackMs> <latencyFile> <statsFile>' . PHP_EOL);
    exit(1);
}
[, $mode, $seconds, $sleepMs, $fallbackMs, , $statsFile] = $arguments;

final class BenchCountingStore implements TaskStore
{
    public int $reserves = 0;

    public function __construct(
        private readonly TaskStore $store,
    ) {}

    public function schema(): array
    {
        return $this->store->schema();
    }

    public function push(EncodedTask $task, ?DateTimeImmutable $notBefore = null): bool
    {
        return $this->store->push($task, $notBefore);
    }

    public function reserve(array $queues, string $owner, int $leaseSeconds): ?ReservedTask
    {
        $this->reserves++;
        return $this->store->reserve($queues, $owner, $leaseSeconds);
    }

    public function complete(ReservedTask $task): bool
    {
        return $this->store->complete($task);
    }

    public function release(ReservedTask $task, int $delaySeconds, string $error): bool
    {
        return $this->store->release($task, $delaySeconds, $error);
    }

    public function fail(ReservedTask $task, string $error): bool
    {
        return $this->store->fail($task, $error);
    }

    public function recover(): int
    {
        return $this->store->recover();
    }

    public function failed(int $limit = 50): array
    {
        return $this->store->failed($limit);
    }

    public function retryFailed(int $id): bool
    {
        return $this->store->retryFailed($id);
    }

    public function deleteFailed(int $id): bool
    {
        return $this->store->deleteFailed($id);
    }

    public function stats(?string $queue = null): TaskStats
    {
        return $this->store->stats($queue);
    }

    public function purge(int $olderThanSeconds, ?int $failedOlderThanSeconds = null): int
    {
        return $this->store->purge($olderThanSeconds, $failedOlderThanSeconds);
    }
}

$pdo = benchConnect();
$connection = new CountingConnection(new PdoConnection($pdo));
$store = benchStore($connection);
$counting = new BenchCountingStore($store);
$waiter = $mode === 'notify'
    ? PostgresNotifyWaiter::fromDsn($store, benchDsn(), fallbackMs: (int) $fallbackMs)
    : null;
$worker = new Worker(
    $counting,
    benchRegistry(),
    static fn(object $task) => $task(),
    waiter: $waiter,
);
$started = microtime(true);
touch($statsFile . '.ready');

$tasks = $worker->run(new WorkerOptions(
    timeout: 0,
    maxTasks: 1_000_000,
    maxRuntime: (int) $seconds,
    sleepMs: (int) $sleepMs,
    recoverInterval: 60,
));

$usage = getrusage();
file_put_contents($statsFile, json_encode([
    'mode' => $mode,
    'tasks' => $tasks,
    'reserves' => $counting->reserves,
    'queries' => $connection->queries,
    'elapsed' => microtime(true) - $started,
    'cpu_user' => $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1_000_000,
    'cpu_sys' => $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1_000_000,
], JSON_THROW_ON_ERROR));
