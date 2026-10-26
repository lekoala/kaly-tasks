<?php

declare(strict_types=1);

namespace Kaly\Tasks\Connection;

use Closure;
use LogicException;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * A PDO handle, or a closure resolving it on each statement when the handle
 * may change (lazy or reconnecting connections).
 *
 * To join an application transaction, the closure must return the handle that
 * runs it: a different handle writes outside the transaction.
 */
final readonly class PdoConnection implements TransactionalConnection
{
    /**
     * @param PDO|Closure():PDO $pdo
     */
    public function __construct(
        private PDO|Closure $pdo,
    ) {}

    /**
     * @template T
     * @param Closure(Connection): T $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        $pdo = $this->pdo instanceof PDO ? $this->pdo : ($this->pdo)();
        if ($pdo->inTransaction()) {
            throw new LogicException('Reservation requires a connection without an active transaction');
        }
        if (!$pdo->beginTransaction()) {
            throw new RuntimeException('Could not begin reservation transaction');
        }
        try {
            $result = $callback(new self($pdo));
            if (!$pdo->commit()) {
                throw new RuntimeException('Could not commit reservation transaction');
            }
            return $result;
        } catch (Throwable $error) {
            try {
                $pdo->rollBack();
            } catch (Throwable) {
                // A lost connection must not hide the original failure
                throw $error;
            }
            throw $error;
        }
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string,mixed>> */
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<int|string|null> $params
     */
    private function run(string $sql, array $params): PDOStatement
    {
        $pdo = $this->pdo instanceof PDO ? $this->pdo : ($this->pdo)();
        $statement = $pdo->prepare($sql);
        if ($statement === false) {
            $error = $pdo->errorInfo()[2] ?? null;
            throw new RuntimeException('Statement failed: ' . (is_string($error) ? $error : 'unknown error'));
        }
        foreach ($params as $i => $value) {
            $statement->bindValue($i + 1, $value, match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            });
        }
        if (!$statement->execute()) {
            $error = $statement->errorInfo()[2] ?? null;
            throw new RuntimeException('Statement failed: ' . (is_string($error) ? $error : 'unknown error'));
        }
        return $statement;
    }
}
