<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Closure;
use Kaly\Tasks\Connection\Connection;
use Kaly\Tasks\Connection\PdoConnection;
use Kaly\Tasks\Connection\TransactionalConnection;
use Kaly\Tasks\EncodedTask;
use Kaly\Tasks\Store\MysqlTaskStore;
use Kaly\Tasks\Tests\Engine\OnMysql;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class MysqlReservationFailureTest extends StoreTestCase
{
    use OnMysql;

    /** @return iterable<string,array{bool}> */
    public static function failures(): iterable
    {
        yield 'after select' => [false];
        yield 'after update' => [true];
    }

    #[DataProvider('failures')]
    public function testInterruptedReservationIsRolledBack(bool $afterUpdate): void
    {
        $this->store()->push(new EncodedTask('a', 'default', '{}'));
        $real = new PdoConnection($this->pdo());
        $failure = new RuntimeException('Reservation interrupted');
        $interrupted = new InterruptedReservationConnection(
            $real,
            afterFetch: $afterUpdate ? null : static fn() => throw $failure,
            afterExecute: static fn() => throw $failure,
        );
        $connection = $this->createStub(TransactionalConnection::class);
        $connection
            ->method('transaction')
            ->willReturnCallback(static fn(Closure $callback) => $real->transaction(
                static fn(Connection $pinned) => $callback($interrupted),
            ));
        $store = new MysqlTaskStore($connection, self::TABLE);
        try {
            $store->reserve(['default'], 'interrupted', 60);
        } catch (RuntimeException $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertFalse($this->pdo()->inTransaction());
        $this->assertSame('pending', $this->row(1)['status']);
        $this->assertSame(0, $this->row(1)['attempts']);
        $this->assertSame(1, $this->store()->reserve(['default'], 'next', 60)?->attempts);
    }

    public function testConnectionLostBeforeCommitDoesNotReturnAReservation(): void
    {
        $this->store()->push(new EncodedTask('a', 'default', '{}'));
        $real = new PdoConnection($this->engine()->connect());
        $killer = new PdoConnection($this->engine()->connect());
        $id = $real->fetchAll('SELECT CONNECTION_ID() AS id')[0]['id'];
        $this->assertIsInt($id);
        $interrupted = new InterruptedReservationConnection($real, afterExecute: static fn() => $killer->execute('KILL CONNECTION ' . $id));
        $connection = $this->createStub(TransactionalConnection::class);
        $connection
            ->method('transaction')
            ->willReturnCallback(static fn(Closure $callback) => $real->transaction(
                static fn(Connection $pinned) => $callback($interrupted),
            ));
        try {
            (new MysqlTaskStore($connection, self::TABLE))->reserve(['default'], 'lost', 60);
            $this->fail('A lost connection must not return a reservation');
        } catch (PDOException $error) {
            $failure = $error;
        }
        $this->assertInstanceOf(PDOException::class, $failure);
        $this->assertSame('pending', $this->row(1)['status']);
        $this->assertSame(0, $this->row(1)['attempts']);
        $store = new MysqlTaskStore(new PdoConnection($this->pdo()), self::TABLE);
        $this->assertSame(1, $store->reserve(['default'], 'next', 60)?->attempts);
    }
}

/** Test connection that interrupts a reservation after a real SQL statement. */
final readonly class InterruptedReservationConnection implements Connection
{
    /**
     * @param Closure():mixed|null $afterFetch
     * @param Closure():mixed|null $afterExecute
     */
    public function __construct(
        private Connection $connection,
        private ?Closure $afterFetch = null,
        private ?Closure $afterExecute = null,
    ) {}

    public function fetchAll(string $sql, array $params = []): array
    {
        $rows = $this->connection->fetchAll($sql, $params);
        if ($this->afterFetch !== null) {
            ($this->afterFetch)();
        }
        return $rows;
    }

    public function execute(string $sql, array $params = []): int
    {
        $count = $this->connection->execute($sql, $params);
        if ($this->afterExecute !== null) {
            ($this->afterExecute)();
        }
        return $count;
    }
}
