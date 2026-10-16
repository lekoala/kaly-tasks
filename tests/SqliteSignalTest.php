<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Kaly\Tasks\Tests\Engine\OnSqlite;

final class SqliteSignalTest extends SignalTestCase
{
    use OnSqlite;
}
