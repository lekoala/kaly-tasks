<?php

// A worker process for the concurrency test: php worker.php <dsn> <table>

declare(strict_types=1);

use Kaly\Di\Container;
use Kaly\Di\Injector;
use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\Store\PostgresTaskStore;
use Kaly\Tasks\TaskRegistry;
use Kaly\Tasks\Tests\Fixtures\LogTask;
use Kaly\Tasks\Worker;
use Kaly\Tasks\WorkerOptions;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $dsn, $table] = $argv;
$registry = (new TaskRegistry())->register('test.log', LogTask::class);
$store = new PostgresTaskStore(new PdoConnection(new PDO($dsn)), $table);
$worker = new Worker($store, $registry, new Injector(new Container([PDO::class => new PDO($dsn)])));

echo $worker->run(new WorkerOptions(timeout: 0, sleepMs: 0, stopWhenEmpty: true));
