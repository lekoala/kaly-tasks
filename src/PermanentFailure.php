<?php

declare(strict_types=1);

namespace Kaly\Tasks;

use RuntimeException;

/**
 * Thrown by a running task that must not be retried, such as invalid data.
 *
 * The worker fails the task at once, whatever attempts remain. Throw it from
 * the task or its adapters, not from domain code that should not depend on
 * the queue.
 */
class PermanentFailure extends RuntimeException {}
