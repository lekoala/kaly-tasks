<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Kaly\Tasks\Connection\TransactionalConnection;

/**
 * The whole store suite through the Doctrine connection.
 */
final class DoctrinePostgresTaskStoreTest extends PostgresTaskStoreTest
{
    protected function connection(): TransactionalConnection
    {
        return $this->postgres()->viaDoctrine()['connection'];
    }
}
