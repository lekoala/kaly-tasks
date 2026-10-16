<?php

declare(strict_types=1);

namespace Kaly\Tasks\Connection;

/**
 * The SQL access a task store needs: nothing more than running a statement.
 *
 * The connection decides the guarantees of `dispatch()`. Share the
 * application connection and a task joins the current business transaction;
 * give a dedicated database and the queue stays out of the application schema,
 * without that atomicity.
 *
 * Statements use positional `?` placeholders. Every statement, reads included,
 * runs on the primary: `fetchAll()` also reserves tasks.
 */
interface Connection
{
    /**
     * @param non-empty-string $sql
     * @param list<int|string|null> $params
     * @return int The number of affected rows
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * @param non-empty-string $sql
     * @param list<int|string|null> $params
     * @return list<array<string,mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array;
}
