<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use InvalidArgumentException;
use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\EncodedTask;
use Kaly\Tasks\Store\MysqlTaskStore;
use Kaly\Tasks\Tests\Engine\OnMysql;
use PDOException;

class MysqlTaskStoreTest extends TaskStoreTestCase
{
    use OnMysql;

    public function testSkipsLockedTasksAndCommitsBeforeReturning(): void
    {
        $this->store()->push(new EncodedTask('a', 'default', '{}'));
        $this->store()->push(new EncodedTask('b', 'default', '{}'));
        $other = $this->engine()->connect();
        $other->beginTransaction();
        try {
            (new PdoConnection($other))->fetchAll('SELECT id FROM ' . self::TABLE . ' WHERE id = 1 FOR UPDATE');
            $task = $this->store()->reserve(['default'], 'worker', 60);
            $this->assertSame(2, $task?->id);
            $this->assertFalse($this->pdo()->inTransaction());
            // Another connection can immediately lock the reserved row
            (new PdoConnection($other))->fetchAll('SELECT id FROM ' . self::TABLE . ' WHERE id = 2 FOR UPDATE NOWAIT');
        } finally {
            $other->rollBack();
        }
        $this->assertSame(1, $this->store()->reserve(['default'], 'next', 60)?->id);
    }

    public function testDeduplicationIsBytewiseAndSupportsLongKeys(): void
    {
        foreach (['Event', 'event', 'event ', str_repeat("\u{00E9}", 4000)] as $key) {
            $this->assertTrue($this->store()->push(new EncodedTask('a', 'default', '{}', $key)));
            $this->assertFalse($this->store()->push(new EncodedTask('a', 'default', '{}', $key)));
        }
        $this->assertTrue($this->store()->push(new EncodedTask('ab', 'default', '{}', 'c')));
        $this->assertTrue($this->store()->push(new EncodedTask('a', 'default', '{}', 'bc')));
    }

    public function testUsesUtcRegardlessOfSessionTimezone(): void
    {
        $this->pdo()->exec("SET time_zone = '+05:00'");
        try {
            $store = new MysqlTaskStore(new PdoConnection($this->pdo()), self::TABLE);
            $store->push(new EncodedTask('a', 'default', '{}'));
            $task = $store->reserve(['default'], 'worker', 60);
            $this->assertNotNull($task);
            $this->assertTrue($store->release($task, 30, 'retry'));
            $this->assertNull($store->reserve(['default'], 'worker', 60));
        } finally {
            $this->pdo()->exec("SET time_zone = '+00:00'");
        }
    }

    public function testDoesNotSuppressInvalidData(): void
    {
        $this->pdo()->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
        $store = new MysqlTaskStore(new PdoConnection($this->pdo()), self::TABLE);
        $this->expectException(PDOException::class);
        $store->push(new EncodedTask('a', str_repeat('q', 256), '{}'));
    }

    public function testRejectsUnsafeTableNames(): void
    {
        new MysqlTaskStore(new PdoConnection($this->pdo()), str_repeat('t', 64));
        $this->expectException(InvalidArgumentException::class);
        new MysqlTaskStore(new PdoConnection($this->pdo()), 'tasks; DROP TABLE users');
    }

    public function testRejectsOverlongTableNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MysqlTaskStore(new PdoConnection($this->pdo()), str_repeat('t', 65));
    }
}
