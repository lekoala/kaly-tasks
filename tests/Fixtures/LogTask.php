<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests\Fixtures;

use PDO;

/**
 * Inserts its number into a log table, from any process.
 */
final readonly class LogTask
{
    public function __construct(
        public int $number,
    ) {}

    public function __invoke(PDO $pdo): void
    {
        $pdo->prepare('INSERT INTO kaly_tasks_log (number, pid) VALUES (?, ?)')->execute([$this->number, getmypid()]);
    }
}
