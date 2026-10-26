<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Kaly\Tasks\Connection\Connection;

/**
 * The whole store suite through the Cycle connection.
 */
final class CycleMysqlTaskStoreTest extends MysqlTaskStoreTest
{
    protected function connection(): Connection
    {
        return $this->mysql()->viaCycle()['connection'];
    }
}
