<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Kaly\Tasks\Tests\Engine\OnPostgres;

final class PostgresSignalTest extends SignalTestCase
{
    use OnPostgres;
}
