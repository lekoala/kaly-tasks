<?php

// A worker process for the signal tests: php signal-worker.php <engine> <dsn> <table> <timeout>

declare(strict_types=1);

use Kaly\Di\Container;
use Kaly\Di\Injector;
use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\TaskRegistry;
use Kaly\Tasks\Tests\Engine\MysqlEngine;
use Kaly\Tasks\Tests\Engine\PostgresEngine;
use Kaly\Tasks\Tests\Engine\SqliteEngine;
use Kaly\Tasks\Tests\Fixtures\SleepTask;
use Kaly\Tasks\Worker;
use Kaly\Tasks\WorkerOptions;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// $argv may not be defined: read the same list from $_SERVER and validate it
$arguments = is_array($_SERVER['argv'] ?? null) ? array_values(array_filter($_SERVER['argv'], is_string(...))) : [];
if (count($arguments) !== 5) {
    fwrite(STDERR, 'Usage: php signal-worker.php <engine> <dsn> <table> <timeout>' . PHP_EOL);
    exit(1);
}
[, $engine, $dsn, $table, $timeout] = $arguments;
$engine = match ($engine) {
    'sqlite' => new SqliteEngine($dsn),
    'mysql' => new MysqlEngine($dsn),
    default => new PostgresEngine($dsn),
};
$registry = (new TaskRegistry())->register('test.sleep', SleepTask::class);
$store = $engine->store(new PdoConnection($engine->connect()), $table);
$worker = new Worker($store, $registry, new Injector(new Container()));

echo $worker->run(new WorkerOptions(lease: 60, timeout: (int) $timeout, sleepMs: 100, stopWhenEmpty: true));
