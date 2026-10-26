<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Kaly\Tasks\Connection\Connection;

/**
 * The whole store suite through the Doctrine connection.
 */
final class DoctrineMysqlTaskStoreTest extends MysqlTaskStoreTest
{
    protected function connection(): Connection
    {
        return $this->mysql()->viaDoctrine()['connection'];
    }
}
