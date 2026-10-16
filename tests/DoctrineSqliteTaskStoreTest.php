<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use InvalidArgumentException;
use Kaly\Tasks\Connection\Connection;
use Kaly\Tasks\Connection\DoctrineConnection;
use Kaly\Tasks\Tests\Engine\SqliteEngine;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Psr\Log\NullLogger;

/**
 * The whole store suite through Doctrine with pdo_sqlite, and the refusal of
 * the sqlite3 driver.
 */
final class DoctrineSqliteTaskStoreTest extends SqliteTaskStoreTest
{
    protected function connection(): Connection
    {
        return new DoctrineConnection(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->sqlite()->path]));
    }

    #[RequiresPhpExtension('sqlite3')]
    public function testRefusesTheSqlite3Driver(): void
    {
        $this->expectExceptionMessage('The Doctrine sqlite3 driver runs a statement again when its rows are fetched, use pdo_sqlite');
        new DoctrineConnection(DriverManager::getConnection(['driver' => 'sqlite3', 'path' => $this->sqlite()->path]));
    }

    #[RequiresPhpExtension('sqlite3')]
    public function testRefusesTheSqlite3DriverBehindAMiddleware(): void
    {
        $configuration = (new Configuration())->setMiddlewares([new Middleware(new NullLogger())]);
        $dbal = DriverManager::getConnection(['driver' => 'sqlite3', 'path' => $this->sqlite()->path], $configuration);
        $connection = new DoctrineConnection($dbal);

        foreach (['execute', 'fetchAll'] as $method) {
            try {
                $connection->{$method}('SELECT 1');
                $this->fail("{$method}() ran on the sqlite3 driver");
            } catch (InvalidArgumentException $e) {
                $this->assertSame(
                    'The Doctrine sqlite3 driver runs a statement again when its rows are fetched, use pdo_sqlite',
                    $e->getMessage(),
                );
            }
        }
    }

    private function sqlite(): SqliteEngine
    {
        $this->assertInstanceOf(SqliteEngine::class, $this->engine);
        return $this->engine;
    }
}
