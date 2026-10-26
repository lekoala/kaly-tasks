<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\EncodedTask;
use Kaly\Tasks\Store\PostgresTaskStore;
use Kaly\Tasks\Tests\Engine\OnPostgres;

class PostgresTaskStoreTest extends TaskStoreTestCase
{
    use OnPostgres;

    public function testSchedulingPreservesMicrosecondsAcrossSessionTimezones(): void
    {
        $this->pdo()->exec("SET TIME ZONE '+05:00'");
        try {
            $store = new PostgresTaskStore(new PdoConnection($this->pdo()), self::TABLE);
            $store->push(new EncodedTask('fractional', 'default', '{}'), new DateTimeImmutable('2030-01-01T05:30:00.123456+05:30'));
            $stored = $this->row(1)['available_at'];
            $this->assertIsString($stored);
            $this->assertEquals(new DateTimeImmutable('2030-01-01T00:00:00.123456Z'), new DateTimeImmutable($stored));
        } finally {
            $this->pdo()->exec("SET TIME ZONE 'UTC'");
        }
    }

    public function testRejectsUnsafeTableNames(): void
    {
        new PostgresTaskStore(new PdoConnection($this->pdo()), 'queue.tasks');

        $this->expectException(InvalidArgumentException::class);
        new PostgresTaskStore(new PdoConnection($this->pdo()), 'tasks; DROP TABLE users');
    }

    public function testRejectsTableNamesThatWouldTruncateIndexNames(): void
    {
        new PostgresTaskStore(new PdoConnection($this->pdo()), str_repeat('t', 55));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be at most 55 characters');
        new PostgresTaskStore(new PdoConnection($this->pdo()), str_repeat('t', 56));
    }
}
