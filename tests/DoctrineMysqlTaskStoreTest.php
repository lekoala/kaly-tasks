<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Kaly\Tasks\Connection\TransactionalConnection;

/**
 * The whole store suite through the Doctrine connection.
 */
final class DoctrineMysqlTaskStoreTest extends MysqlTaskStoreTest
{
    protected function connection(): TransactionalConnection
    {
        return $this->mysql()->viaDoctrine()['connection'];
    }
}
