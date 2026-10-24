<?php

// A worker process for the concurrency test: php worker.php <engine> <dsn> <table> <barrier>

declare(strict_types=1);

use Kaly\Di\Container;
use Kaly\Di\Injector;
use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\TaskRegistry;
use Kaly\Tasks\Tests\Engine\PostgresEngine;
use Kaly\Tasks\Tests\Engine\SqliteEngine;
use Kaly\Tasks\Tests\Fixtures\LogTask;
use Kaly\Tasks\Worker;
use Kaly\Tasks\WorkerOptions;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// $argv may not be defined: read the same list from $_SERVER and validate it
$arguments = is_array($_SERVER['argv'] ?? null) ? array_values(array_filter($_SERVER['argv'], is_string(...))) : [];
if (count($arguments) !== 5) {
    fwrite(STDERR, 'Usage: php worker.php <engine> <dsn> <table> <barrier>' . PHP_EOL);
    exit(1);
}
[, $engine, $dsn, $table, $barrier] = $arguments;
$engine = $engine === 'sqlite' ? new SqliteEngine($dsn) : new PostgresEngine($dsn);
$registry = (new TaskRegistry())->register('test.log', LogTask::class);
$store = $engine->store(new PdoConnection($engine->connect()), $table);
$worker = new Worker($store, $registry, new Injector(new Container([PDO::class => $engine->connect()])));

// Signals readiness, then starts with the other workers
touch($barrier . '/ready-' . getmypid());
$deadline = microtime(true) + 30;
while (!is_file($barrier . '/go')) {
    if (microtime(true) > $deadline) {
        exit(1);
    }
    usleep(1000);
}

echo $worker->run(new WorkerOptions(timeout: 0, sleepMs: 0, stopWhenEmpty: true));
