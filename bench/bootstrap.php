<?php

declare(strict_types=1);

/**
 * Shared setup for the LISTEN/NOTIFY benchmark.
 *
 * Set KALY_TASKS_BENCH_DSN to point at another PostgreSQL. The table is
 * recreated for every scenario, so never run this against a real queue.
 */

use Kaly\Tasks\Connection\Connection;
use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\Connection\TransactionalConnection;
use Kaly\Tasks\Store\PostgresTaskStore;
use Kaly\Tasks\TaskRegistry;

require dirname(__DIR__) . '/vendor/autoload.php';

const BENCH_TABLE = 'kaly_tasks_bench';

function benchDsn(): string
{
    $dsn = getenv('KALY_TASKS_BENCH_DSN');
    return is_string($dsn) && $dsn !== ''
        ? $dsn
        : 'pgsql:host=127.0.0.1;port=55432;dbname=kaly_tasks;user=kaly;password=kaly';
}

function benchConnect(): PDO
{
    return new PDO(benchDsn(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function benchStore(TransactionalConnection $connection): PostgresTaskStore
{
    return new PostgresTaskStore($connection, BENCH_TABLE);
}

function benchReset(bool $withTrigger): void
{
    $pdo = benchConnect();
    $store = benchStore(new PdoConnection($pdo));
    foreach ($store->dropNotificationSchema() as $statement) {
        $pdo->exec($statement);
    }
    $pdo->exec('DROP TABLE IF EXISTS ' . BENCH_TABLE);
    foreach ($store->schema() as $statement) {
        $pdo->exec($statement);
    }
    if ($withTrigger) {
        foreach ($store->notificationSchema() as $statement) {
            $pdo->exec($statement);
        }
    }
}

function benchRegistry(): TaskRegistry
{
    return (new TaskRegistry())->register('bench.task', BenchTask::class);
}

/**
 * Counts every statement the worker sends, including the waiter's due query.
 */
final class CountingConnection implements TransactionalConnection
{
    public int $queries = 0;

    public function __construct(
        private readonly TransactionalConnection $connection,
    ) {}

    public function execute(string $sql, array $params = []): int
    {
        $this->queries++;
        return $this->connection->execute($sql, $params);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->queries++;
        return $this->connection->fetchAll($sql, $params);
    }

    public function transaction(Closure $callback): mixed
    {
        return $this->connection->transaction(fn(Connection $inner): mixed => $callback($this));
    }
}

final readonly class BenchTask
{
    public function __construct(
        public int $seq,
        public string $output,
    ) {}

    public function __invoke(): void
    {
        file_put_contents($this->output, sprintf("%d,%.6F\n", $this->seq, microtime(true)), FILE_APPEND | LOCK_EX);
    }
}
