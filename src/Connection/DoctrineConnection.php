<?php

declare(strict_types=1);

namespace Kaly\Tasks\Connection;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\DBAL\ParameterType;

/**
 * A Doctrine DBAL connection. Statements run inside its current transaction,
 * including one opened by an EntityManager on this connection, always on the
 * primary of a primary/replica connection: a reservation writes, and reads
 * must see uncommitted dispatches.
 */
final readonly class DoctrineConnection implements Connection
{
    public function __construct(
        private DbalConnection $connection,
    ) {}

    public function execute(string $sql, array $params = []): int
    {
        return (int) $this->connection->executeStatement($sql, $params, self::types($params));
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        if ($this->connection instanceof PrimaryReadReplicaConnection) {
            $this->connection->ensureConnectedToPrimary();
        }
        /** @var list<array<string,mixed>> */
        return $this->connection->fetchAllAssociative($sql, $params, self::types($params));
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
