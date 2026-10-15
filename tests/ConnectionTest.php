<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Kaly\Tasks\DatabaseTaskDispatcher;
use Kaly\Tasks\Store\PostgresTaskStore;
use Kaly\Tasks\TaskRegistry;
use Kaly\Tasks\Tests\Fixtures\RecordTask;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The outbox guarantee through each supported connection: a task dispatched
 * inside the application transaction commits and rolls back with it.
 */
class ConnectionTest extends PostgresTestCase
{
    /**
     * @return iterable<string,array{string}>
     */
    public static function connections(): iterable
    {
        yield 'pdo' => ['pdo'];
        yield 'doctrine' => ['doctrine'];
        yield 'cycle' => ['cycle'];
    }

    #[DataProvider('connections')]
    public function testDispatchJoinsTheApplicationTransaction(string $library): void
    {
        $application = match ($library) {
            'pdo' => $this->viaPdo(),
            'doctrine' => $this->viaDoctrine(),
            default => $this->viaCycle(),
        };
        $store = new PostgresTaskStore($application['connection'], self::TABLE);
        $tasks = new DatabaseTaskDispatcher($store, (new TaskRegistry())->register('test.record', RecordTask::class));

        $application['begin']();
        $tasks->dispatch(new RecordTask('rolled back'));
        $application['rollback']();
        $this->assertSame(0, $this->countTasks());

        $application['begin']();
        $tasks->dispatch(new RecordTask('committed'));
        $this->assertSame(0, $this->countTasks(), 'Invisible to other connections before the commit');
        $application['commit']();
        $this->assertSame(1, $this->countTasks());

        $reserved = $store->reserve(['default'], 'owner', 60);
        $this->assertNotNull($reserved);
        $this->assertSame(['test.record', '{"entry": "committed"}'], [$reserved->name, $reserved->payload]);
        $store->fail($reserved, 'Broken');
        $this->assertSame([$reserved->id], array_column($store->failed(10), 'id'));
    }
}
