<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Kaly\Tasks\Connection\TransactionalConnection;

/**
 * The whole store suite through the Cycle connection.
 */
final class CyclePostgresTaskStoreTest extends PostgresTaskStoreTest
{
    protected function connection(): TransactionalConnection
    {
        return $this->postgres()->viaCycle()['connection'];
    }
}
