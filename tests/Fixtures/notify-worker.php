<?php

// An idle worker with a PostgreSQL notification waiter, for signal tests:
// php notify-worker.php <engine> <dsn> <table> <maxRuntime> <fallbackMs>

declare(strict_types=1);

use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\Store\PostgresNotifyWaiter;
use Kaly\Tasks\Store\PostgresTaskStore;
use Kaly\Tasks\TaskRegistry;
use Kaly\Tasks\Tests\Engine\PostgresEngine;
use Kaly\Tasks\Worker;
use Kaly\Tasks\WorkerOptions;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// $argv may not be defined: read the same list from $_SERVER and validate it
$arguments = is_array($_SERVER['argv'] ?? null) ? array_values(array_filter($_SERVER['argv'], is_string(...))) : [];
if (count($arguments) !== 6) {
    fwrite(STDERR, 'Usage: php notify-worker.php <engine> <dsn> <table> <maxRuntime> <fallbackMs>' . PHP_EOL);
    exit(1);
}
[, $engine, $dsn, $table, $maxRuntime, $fallbackMs] = $arguments;
$engine = new PostgresEngine($dsn);
$store = $engine->store(new PdoConnection($engine->connect()), $table);
if (!$store instanceof PostgresTaskStore) {
    fwrite(STDERR, 'The notify worker needs a PostgreSQL store' . PHP_EOL);
    exit(1);
}
$waiter = PostgresNotifyWaiter::fromDsn($store, $dsn, fallbackMs: (int) $fallbackMs);
$worker = new Worker($store, new TaskRegistry(), static fn(object $task) => $task(), waiter: $waiter);

echo $worker->run(new WorkerOptions(timeout: 0, maxRuntime: (int) $maxRuntime, sleepMs: 0));
