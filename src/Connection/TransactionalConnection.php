<?php

declare(strict_types=1);

namespace Kaly\Tasks\Connection;

use Closure;

/** A short, top-level transaction on a pinned primary connection. */
interface TransactionalConnection extends Connection
{
    /**
     * Commits before returning and rolls back on failure. An existing transaction
     * is refused: reservations and sequence transitions must commit before returning.
     * The callback must use the supplied connection and must not manage transactions.
     *
     * @template T
     * @param Closure(Connection): T $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed;
}
