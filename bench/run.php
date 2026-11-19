<?php

declare(strict_types=1);

/**
 * Compares the fixed sleep with the opt-in LISTEN/NOTIFY waiter.
 *
 * php bench/run.php
 *
 * Requires the PostgreSQL instance from compose.yaml, or KALY_TASKS_BENCH_DSN.
 * Each scenario recreates its own table; never point this at a real queue.
 */

use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\EncodedTask;

require __DIR__ . '/bootstrap.php';

const BENCH_RUNS = __DIR__ . '/runs';

/**
 * @param list<float> $values
 */
function percentile(array $values, float $p): float
{
    if ($values === []) {
        return NAN;
    }
    sort($values);
    return $values[max(0, min(count($values) - 1, (int) ceil($p * count($values)) - 1))];
}

/**
 * @param list<array{0:resource,1:array<int,resource>}> $workers
 */
function waitForWorkers(array $workers): void
{
    foreach ($workers as [$process, $pipes]) {
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new RuntimeException("Worker exited with {$code}: {$stderr}{$stdout}");
        }
    }
}

function runDispatch(int $first, int $count, string $latencyFile, string $dispatchFile, int $notBeforeMs = 0): void
{
    $command = [PHP_BINARY, __DIR__ . '/dispatch.php', (string) $first, (string) $count, $latencyFile, $dispatchFile, (string) $notBeforeMs];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start dispatcher');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        throw new RuntimeException("Dispatcher failed: {$stderr}{$stdout}");
    }
}

/**
 * @param 'poll'|'notify' $mode
 * @param list<array{offset: float, first: int, count: int, notBeforeMs?: int}> $events
 * @return array<string,mixed>
 */
function scenario(
    string $mode,
    int $seconds,
    array $events,
    int $workerCount = 1,
    int $sleepMs = 1000,
    int $fallbackMs = 5000,
): array {
    $dir = BENCH_RUNS . '/' . uniqid('run-', true);
    mkdir($dir, 0o777, true);
    $latencyFile = $dir . '/latency.csv';
    $dispatchFile = $dir . '/dispatch.csv';
    touch($latencyFile);
    touch($dispatchFile);

    benchReset(withTrigger: $mode === 'notify');

    $started = microtime(true);
    $workers = [];
    for ($i = 0; $i < $workerCount; $i++) {
        $statsFile = $dir . "/stats-{$i}.json";
        $command = [
            PHP_BINARY,
            __DIR__ . '/worker.php',
            $mode,
            (string) $seconds,
            (string) $sleepMs,
            (string) $fallbackMs,
            $latencyFile,
            $statsFile,
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start worker');
        }
        $workers[] = [$process, $pipes, $statsFile];
        $deadline = microtime(true) + 5;
        while (!file_exists($statsFile . '.ready')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Worker did not become ready');
            }
            usleep(10_000);
        }
    }

    foreach ($events as $event) {
        $target = $started + $event['offset'];
        while (($wait = $target - microtime(true)) > 0) {
            usleep((int) min(50_000, ceil($wait * 1_000_000)));
        }
        runDispatch($event['first'], $event['count'], $latencyFile, $dispatchFile, $event['notBeforeMs'] ?? 0);
    }

    $remaining = $started + $seconds - microtime(true);
    if ($remaining > 0) {
        usleep((int) ceil($remaining * 1_000_000));
    }
    waitForWorkers(array_map(static fn(array $w): array => [$w[0], $w[1]], $workers));

    $stats = [];
    foreach ($workers as [, , $statsFile]) {
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($statsFile), true, 512, JSON_THROW_ON_ERROR);
        $stats[] = $decoded;
    }

    $dispatched = [];
    foreach (file($dispatchFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        [$seq, $before, $after, $due] = explode(',', $line);
        $dispatched[(int) $seq] = [(float) $before, (float) $after, (float) $due];
    }
    $starts = [];
    foreach (file($latencyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        [$seq, $at] = explode(',', $line);
        $starts[(int) $seq][] = (float) $at;
    }

    $latencies = [];
    $lateness = [];
    $duplicates = 0;
    $missing = 0;
    foreach ($dispatched as $seq => [, $after, $due]) {
        $runs = $starts[$seq] ?? [];
        $duplicates += max(0, count($runs) - 1);
        if ($runs === []) {
            $missing++;
            continue;
        }
        $latencies[] = min($runs) - $after;
        $lateness[] = min($runs) - $due;
    }
    $allStarts = $starts === [] ? [] : array_merge(...array_values($starts));

    $reserves = (int) array_sum(array_column($stats, 'reserves'));
    $queries = (int) array_sum(array_column($stats, 'queries'));

    return [
        'mode' => $mode . '-' . ($mode === 'poll' ? $sleepMs : $fallbackMs),
        'workers' => $workerCount,
        'dispatched' => count($dispatched),
        'tasks' => (int) array_sum(array_column($stats, 'tasks')),
        'duplicates' => $duplicates,
        'missing' => $missing,
        'reserves' => $reserves,
        'queries' => $queries,
        'queries_per_second' => round($queries / $seconds, 2),
        'cpu_seconds' => round(array_sum(array_column($stats, 'cpu_user')) + array_sum(array_column($stats, 'cpu_sys')), 3),
        'latency_ms_p50' => $latencies === [] ? null : round(percentile($latencies, 0.5) * 1000, 2),
        'latency_ms_p95' => $latencies === [] ? null : round(percentile($latencies, 0.95) * 1000, 2),
        'latency_ms_max' => $latencies === [] ? null : round(max($latencies) * 1000, 2),
        'lateness_ms_p50' => $lateness === [] ? null : round(percentile($lateness, 0.5) * 1000, 2),
        'lateness_ms_max' => $lateness === [] ? null : round(max($lateness) * 1000, 2),
        'drain_ms' => $allStarts === [] ? null : round((max($allStarts) - min(array_column($dispatched, 0))) * 1000, 2),
    ];
}

/** @return list<array{offset: float, first: int, count: int}> */
function isolatedEvents(int $count = 12, float $start = 2.0, float $gap = 1.13): array
{
    $events = [];
    for ($i = 0; $i < $count; $i++) {
        $events[] = ['offset' => $start + $i * $gap, 'first' => $i + 1, 'count' => 1];
    }
    return $events;
}

/** @return array<string,mixed> */
function insertOverhead(bool $withTrigger, int $count = 1000): array
{
    benchReset(withTrigger: $withTrigger);
    $store = benchStore(new PdoConnection(benchConnect()));
    $timings = [];
    for ($i = 0; $i < $count; $i++) {
        $start = microtime(true);
        $store->push(new EncodedTask('bench.task', 'default', json_encode(['seq' => $i, 'output' => '/dev/null'], JSON_THROW_ON_ERROR)));
        $timings[] = microtime(true) - $start;
    }
    return [
        'trigger' => $withTrigger,
        'per_insert_us_p50' => round(percentile($timings, 0.5) * 1_000_000, 1),
        'per_insert_us_p95' => round(percentile($timings, 0.95) * 1_000_000, 1),
        'total_ms' => round(array_sum($timings) * 1000, 1),
    ];
}

@mkdir(BENCH_RUNS, 0o777, true);
$results = [];

foreach ([['poll', 1000, 5000], ['poll', 100, 5000], ['notify', 1000, 5000]] as [$mode, $sleep, $fallback]) {
    $results[] = scenario($mode, 16, isolatedEvents(), sleepMs: $sleep, fallbackMs: $fallback) + ['scenario' => 'isolated'];
}
foreach ([['poll', 1000, 5000], ['poll', 100, 5000], ['notify', 1000, 5000]] as [$mode, $sleep, $fallback]) {
    $results[] = scenario($mode, 12, [], sleepMs: $sleep, fallbackMs: $fallback) + ['scenario' => 'idle'];
}
foreach ([['poll', 1000, 5000], ['notify', 1000, 5000]] as [$mode, $sleep, $fallback]) {
    $results[] = scenario($mode, 7, [['offset' => 2, 'first' => 1, 'count' => 1, 'notBeforeMs' => 1500]], sleepMs: $sleep, fallbackMs: $fallback) + ['scenario' => 'deferred'];
}
$results[] = scenario('poll', 10, [['offset' => 3, 'first' => 1, 'count' => 30]]) + ['scenario' => 'burst'];
$results[] = scenario('notify', 10, [['offset' => 3, 'first' => 1, 'count' => 30]]) + ['scenario' => 'burst'];
$results[] = scenario('poll', 10, [['offset' => 3, 'first' => 1, 'count' => 40]], workerCount: 4) + ['scenario' => 'multi'];
$results[] = scenario('notify', 10, [['offset' => 3, 'first' => 1, 'count' => 40]], workerCount: 4) + ['scenario' => 'multi'];

echo json_encode([
    'scenarios' => $results,
    'insert_overhead' => [insertOverhead(false), insertOverhead(true)],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
