<?php

// A concurrent producer for the deduplication test: php producer.php <engine> <dsn> <table> <barrier>

declare(strict_types=1);

use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\EncodedTask;
use Kaly\Tasks\Tests\Engine\MysqlEngine;
use Kaly\Tasks\Tests\Engine\PostgresEngine;
use Kaly\Tasks\Tests\Engine\SqliteEngine;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// $argv may not be defined: read the same list from $_SERVER and validate it
$arguments = is_array($_SERVER['argv'] ?? null) ? array_values(array_filter($_SERVER['argv'], is_string(...))) : [];
if (count($arguments) !== 5) {
    fwrite(STDERR, 'Usage: php producer.php <engine> <dsn> <table> <barrier>' . PHP_EOL);
    exit(1);
}
[, $engine, $dsn, $table, $barrier] = $arguments;
$engine = match ($engine) {
    'sqlite' => new SqliteEngine($dsn),
    'mysql' => new MysqlEngine($dsn),
    default => new PostgresEngine($dsn),
};
$store = $engine->store(new PdoConnection($engine->connect()), $table);

// Signals readiness, then starts with the other workers
touch($barrier . '/ready-' . getmypid());
$deadline = microtime(true) + 30;
while (!is_file($barrier . '/go')) {
    if (microtime(true) > $deadline) {
        exit(1);
    }
    usleep(1000);
}

$inserted = 0;
for ($i = 0; $i < 10; $i++) {
    $inserted += (int) $store->push(new EncodedTask('test.log', 'default', '{}', (string) $i));
}
echo $inserted;
