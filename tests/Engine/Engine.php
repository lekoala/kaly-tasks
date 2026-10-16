<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests\Engine;

use Kaly\Tasks\Connection\Connection;
use Kaly\Tasks\Store\TaskStore;
use PDO;

/**
 * A database the suites run against. Everything engine-specific in the tests
 * lives here.
 */
interface Engine
{
    /**
     * Opens a new handle on the test database.
     */
    public function connect(): PDO;

    public function store(Connection $connection, string $table): TaskStore;

    /**
     * Drops and creates the task table.
     */
    public function reset(PDO $pdo, string $table): void;

    /**
     * Moves every lease, availability and finish time into the past.
     */
    public function age(PDO $pdo, string $table, int $seconds): void;

    /**
     * Arguments for a worker process to rebuild this engine.
     *
     * @return list<string>
     */
    public function arguments(): array;

    public function cleanUp(): void;
}
