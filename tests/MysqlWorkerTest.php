<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests;

use Kaly\Tasks\Tests\Engine\OnMysql;

final class MysqlWorkerTest extends WorkerTestCase
{
    use OnMysql;
}
