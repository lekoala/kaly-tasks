<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests\Engine;

/**
 * Runs a StoreTestCase against MySQL, skipped when KALY_TASKS_MYSQL_DSN is empty.
 */
trait OnMysql
{
    protected function createEngine(): Engine
    {
        return MysqlEngine::fromEnvironment() ?? $this->markTestSkipped('KALY_TASKS_MYSQL_DSN is empty');
    }

    protected function mysql(): MysqlEngine
    {
        $this->assertInstanceOf(MysqlEngine::class, $this->engine());
        return $this->engine();
    }
}
