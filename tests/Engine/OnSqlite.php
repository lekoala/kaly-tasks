<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests\Engine;

/**
 * Runs a StoreTestCase against a temporary SQLite file.
 */
trait OnSqlite
{
    protected function createEngine(): Engine
    {
        return SqliteEngine::temporary();
    }
}
