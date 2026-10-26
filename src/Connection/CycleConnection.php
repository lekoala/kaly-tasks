<?php

declare(strict_types=1);

namespace Kaly\Tasks\Connection;

use Closure;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\Injection\Parameter;
use Cycle\Database\StatementInterface;
use LogicException;
use Throwable;

/**
 * A Cycle database. Statements run inside its current transaction, including
 * one opened by the ORM on this database, always on its write driver: a
 * reservation writes, and reads must see uncommitted dispatches.
 */
final readonly class CycleConnection implements TransactionalConnection
{
    public function __construct(
        private DatabaseInterface $database,
    ) {}

    /**
     * @template T
     * @param Closure(Connection): T $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        $driver = $this->database->getDriver(DatabaseInterface::WRITE);
        if ($driver->getTransactionLevel() !== 0) {
            throw new LogicException('Reservation requires a connection without an active transaction');
        }
        $driver->beginTransaction();
        try {
            $result = $callback($this);
            $driver->commitTransaction();
            return $result;
        } catch (Throwable $error) {
            try {
                $driver->rollbackTransaction();
            } catch (Throwable) {
                // A lost connection must not hide the original failure
                throw $error;
            }
            throw $error;
        }
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->database->execute($sql, self::parameters($params));
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string,mixed>> */
        return $this->database
            ->getDriver(DatabaseInterface::WRITE)
            ->query($sql, self::parameters($params))
            ->fetchAll(StatementInterface::FETCH_ASSOC);
    }

    /**
     * Cycle binds unwrapped values as strings; MySQL LIMIT needs an integer.
     *
     * @param list<int|string|null> $params
     * @return list<Parameter>
     */
    private static function parameters(array $params): array
    {
        return array_map(static fn(int|string|null $value): Parameter => new Parameter($value), $params);
    }
}
