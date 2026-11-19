<?php

declare(strict_types=1);

/**
 * Dispatches a range of benchmark tasks and records their timing.
 *
 * php dispatch.php <first> <count> <latencyFile> <dispatchFile> [notBeforeMs]
 *
 * The latency file is written by the task itself; this process records when
 * each task was committed and when it was due, in the dispatch file.
 */

use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\EncodedTask;

require __DIR__ . '/bootstrap.php';

$arguments = is_array($_SERVER['argv'] ?? null) ? array_values(array_filter($_SERVER['argv'], is_string(...))) : [];
if (count($arguments) < 5 || count($arguments) > 6) {
    fwrite(STDERR, 'Usage: php dispatch.php <first> <count> <latencyFile> <dispatchFile> [notBeforeMs]' . PHP_EOL);
    exit(1);
}
[, $first, $count, $latencyFile, $dispatchFile] = $arguments;
$notBeforeMs = (int) ($arguments[5] ?? 0);
$store = benchStore(new PdoConnection(benchConnect()));
$deadline = $notBeforeMs > 0 ? new DateTimeImmutable('+' . $notBeforeMs . ' milliseconds') : null;

for ($seq = (int) $first; $seq < (int) $first + (int) $count; $seq++) {
    $before = microtime(true);
    $store->push(new EncodedTask(
        'bench.task',
        'default',
        json_encode(['seq' => $seq, 'output' => $latencyFile], JSON_THROW_ON_ERROR),
    ), $deadline);
    $after = microtime(true);
    file_put_contents(
        $dispatchFile,
        sprintf("%d,%.6F,%.6F,%.6F\n", $seq, $before, $after, $after + $notBeforeMs / 1000),
        FILE_APPEND | LOCK_EX,
    );
}
