<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests\Engine;

use Kaly\Tasks\Connection\Connection;
use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\Store\SqliteTaskStore;
use Kaly\Tasks\Store\TaskStore;
use PDO;

/**
 * A SQLite file in WAL mode: a real file, so that several processes share it.
 */
final readonly class SqliteEngine implements Engine
{
    public function __construct(
        public string $path,
    ) {}

    public static function temporary(): self
    {
        return new self(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-tasks-' . bin2hex(random_bytes(8)) . '.sqlite');
    }

    public function connect(): PDO
    {
        // Busy timeout below the pdo_sqlite default of 60s: a stuck test fails sooner
        $pdo = new PDO('sqlite:' . $this->path, options: [PDO::ATTR_TIMEOUT => 30]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        return $pdo;
    }

    public function store(Connection $connection, string $table): TaskStore
    {
        return new SqliteTaskStore($connection, $table);
    }

    public function reset(PDO $pdo, string $table): void
    {
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
        foreach ((new SqliteTaskStore(new PdoConnection($pdo), $table))->schema() as $statement) {
            $pdo->exec($statement);
        }
    }

    public function age(PDO $pdo, string $table, int $seconds): void
    {
        // Unset times stay NULL: only the times a task has move
        $pdo->exec(sprintf(
            'UPDATE %s SET lease_until = lease_until - %2$d, available_at = available_at - %2$d, finished_at = finished_at - %2$d',
            $table,
            $seconds,
        ));
    }

    public function arguments(): array
    {
        return ['sqlite', $this->path];
    }

    public function cleanUp(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($this->path . $suffix)) {
                // Windows keeps the file while a handle is open somewhere
                @unlink($this->path . $suffix);
            }
        }
    }
}
