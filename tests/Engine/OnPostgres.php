<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests\Engine;

/**
 * Runs a StoreTestCase against PostgreSQL, skipped when KALY_TASKS_PGSQL_DSN is empty.
 */
trait OnPostgres
{
    protected function createEngine(): Engine
    {
        return PostgresEngine::fromEnvironment() ?? $this->markTestSkipped('KALY_TASKS_PGSQL_DSN is empty');
    }

    protected function postgres(): PostgresEngine
    {
        $this->assertInstanceOf(PostgresEngine::class, $this->engine());
        return $this->engine();
    }
}
