<?php

declare(strict_types=1);

namespace Kaly\Tasks\Tests\Fixtures;

use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

final class ThrowingLogger extends AbstractLogger
{
    /**
     * Untyped message: compatible with PSR-3 v1, v2 and v3.
     *
     * @param string|Stringable $message
     * @param array<mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        throw new RuntimeException('Logger unavailable');
    }
}
