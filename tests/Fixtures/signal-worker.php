<?php

// A worker process for the signal tests: php signal-worker.php <dsn> <table> <timeout>

declare(strict_types=1);

use Kaly\Di\Container;
use Kaly\Di\Injector;
use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\Store\PostgresTaskStore;
use Kaly\Tasks\TaskRegistry;
use Kaly\Tasks\Tests\Fixtures\SleepTask;
use Kaly\Tasks\Worker;
use Kaly\Tasks\WorkerOptions;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $dsn, $table, $timeout] = $argv;
$registry = (new TaskRegistry())->register('test.sleep', SleepTask::class);
$store = new PostgresTaskStore(new PdoConnection(new PDO($dsn)), $table);
$worker = new Worker($store, $registry, new Injector(new Container()));

echo $worker->run(new WorkerOptions(lease: 60, timeout: (int) $timeout, sleepMs: 100, stopWhenEmpty: true));
