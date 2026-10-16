<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Cycle\Database\Database;
use Cycle\Database\Driver\DriverInterface;
use Cycle\Database\StatementInterface;
use Kaly\Tasks\Connection\CycleConnection;
use PHPUnit\Framework\TestCase;

class CycleConnectionTest extends TestCase
{
    public function testFetchesFromTheWriteDriver(): void
    {
        $statement = $this->createStub(StatementInterface::class);
        $statement->method('fetchAll')->willReturn([['id' => 1]]);
        $write = $this->createMock(DriverInterface::class);
        $write->expects($this->once())->method('query')->with('UPDATE t SET a = ? RETURNING id', [1])->willReturn($statement);
        // A read-only replica would reject the reservation
        $read = $this->createMock(DriverInterface::class);
        $read->expects($this->never())->method('query');

        $connection = new CycleConnection(new Database('default', '', $write, $read));

        $this->assertSame([['id' => 1]], $connection->fetchAll('UPDATE t SET a = ? RETURNING id', [1]));
    }
}
