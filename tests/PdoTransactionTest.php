<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Kaly\Tasks\Connection\Connection;
use Kaly\Tasks\Connection\PdoConnection;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoTransactionTest extends TestCase
{
    public function testResolvesTheHandleOnlyOnceForTheWholeTransaction(): void
    {
        $first = new PDO('sqlite::memory:');
        $second = new PDO('sqlite::memory:');
        $calls = 0;
        $connection = new PdoConnection(static function () use ($first, $second, &$calls): PDO {
            return ++$calls === 1 ? $first : $second;
        });
        $result = $connection->transaction(static function (Connection $pinned): array {
            $pinned->execute('CREATE TABLE pinned (id INTEGER)');
            $pinned->execute('INSERT INTO pinned VALUES (42)');
            return $pinned->fetchAll('SELECT id FROM pinned');
        });
        $this->assertSame([['id' => 42]], $result);
        $this->assertSame(1, $calls);
        $this->assertFalse($first->inTransaction());
    }
}
