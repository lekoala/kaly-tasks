<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Kaly\Tasks\Tests\Engine\OnPostgres;

final class PostgresConcurrencyTest extends ConcurrencyTestCase
{
    use OnPostgres;
}
