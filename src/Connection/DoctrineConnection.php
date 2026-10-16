<?php

declare(strict_types=1);

namespace Kaly\Tasks\Connection;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\Driver\SQLite3\Driver as Sqlite3Driver;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use SQLite3;

/**
 * A Doctrine DBAL connection. Statements run inside its current transaction,
 * including one opened by an EntityManager on this connection, always on the
 * primary of a primary/replica connection: a reservation writes, and reads
 * must see uncommitted dispatches.
 *
 * The `sqlite3` driver is refused, use `pdo_sqlite`: ext-sqlite3 runs a
 * statement again when its rows are fetched, so a reservation would claim a
 * second task. See PHP bugs #64531 (fetchArray() runs the query again) and
 * #81376 (UPDATE … RETURNING).
 */
final readonly class DoctrineConnection implements Connection
{
    private const SQLITE3_REFUSED = 'The Doctrine sqlite3 driver runs a statement again when its rows are fetched, use pdo_sqlite';

    public function __construct(
        private DbalConnection $connection,
    ) {
        if ($connection->getDriver() instanceof Sqlite3Driver) {
            throw new InvalidArgumentException(self::SQLITE3_REFUSED);
        }
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->assertNotSqlite3();
        return (int) $this->connection->executeStatement($sql, $params, self::types($params));
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        if ($this->connection instanceof PrimaryReadReplicaConnection) {
            $this->connection->ensureConnectedToPrimary();
        }
        $this->assertNotSqlite3();
        /** @var list<array<string,mixed>> */
        return $this->connection->fetchAllAssociative($sql, $params, self::types($params));
    }

    /**
     * A driver middleware hides the sqlite3 driver from the constructor.
     */
    private function assertNotSqlite3(): void
    {
        if ($this->connection->getNativeConnection() instanceof SQLite3) {
            throw new InvalidArgumentException(self::SQLITE3_REFUSED);
        }
    }

    /**
     * @param list<int|string|null> $params
     * @return list<ParameterType>
     */
    private static function types(array $params): array
    {
        return array_map(static fn(int|string|null $value): ParameterType => match (true) {
            $value === null => ParameterType::NULL,
            is_int($value) => ParameterType::INTEGER,
            default => ParameterType::STRING,
        }, $params);
    }
}
