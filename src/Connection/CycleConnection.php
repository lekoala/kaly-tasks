<?php

declare(strict_types=1);

namespace Kaly\Tasks\Connection;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;

/**
 * A Cycle database. Statements run inside its current transaction, including
 * one opened by the ORM on this database.
 */
final readonly class CycleConnection implements Connection
{
    public function __construct(
        private DatabaseInterface $database,
    ) {}

    public function execute(string $sql, array $params = []): int
    {
        return $this->database->execute($sql, $params);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string,mixed>> */
        return $this->database->query($sql, $params)->fetchAll(StatementInterface::FETCH_ASSOC);
    }
}
