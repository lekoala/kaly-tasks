<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use InvalidArgumentException;
use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\Store\PostgresTaskStore;
use Kaly\Tasks\Tests\Engine\OnPostgres;

class PostgresTaskStoreTest extends TaskStoreTestCase
{
    use OnPostgres;

    public function testRejectsUnsafeTableNames(): void
    {
        new PostgresTaskStore(new PdoConnection($this->pdo), 'queue.tasks');

        $this->expectException(InvalidArgumentException::class);
        new PostgresTaskStore(new PdoConnection($this->pdo), 'tasks; DROP TABLE users');
    }

    public function testRejectsTableNamesThatWouldTruncateIndexNames(): void
    {
        new PostgresTaskStore(new PdoConnection($this->pdo), str_repeat('t', 55));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be at most 55 characters');
        new PostgresTaskStore(new PdoConnection($this->pdo), str_repeat('t', 56));
    }
}
