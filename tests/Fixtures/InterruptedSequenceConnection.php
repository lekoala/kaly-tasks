<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests\Fixtures;

use Kaly\Tasks\Connection\Connection;
use RuntimeException;

/** Interrupts a transition after a real statement on its pinned connection. */
final class InterruptedSequenceConnection implements Connection
{
    private int $calls = 0;

    public function __construct(
        private readonly Connection $connection,
        private readonly RuntimeException $failure,
        private readonly int $failAfter,
    ) {}

    public function execute(string $sql, array $params = []): int
    {
        $result = $this->connection->execute($sql, $params);
        if (++$this->calls === $this->failAfter) {
            throw $this->failure;
        }
        return $result;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->connection->fetchAll($sql, $params);
    }
}
