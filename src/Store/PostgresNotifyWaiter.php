<?php

declare(strict_types=1);

namespace Kaly\Tasks\Store;

use Closure;
use InvalidArgumentException;
use Kaly\Tasks\IdleWaiter;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Wakes an idle PostgreSQL worker with LISTEN/NOTIFY.
 *
 * A dedicated connection listens on a channel derived from the task table. The
 * table stays the source of truth: a signal only makes the worker look again,
 * and a fallback poll covers a lost notification, a reconnect or a missing
 * trigger.
 *
 * On PHP 8.4 and later, create the listener with `PDO::connect()` or
 * `new Pdo\Pgsql()` so `Pdo\Pgsql::getNotify()` is available. On PHP 8.3, a
 * plain `new PDO('pgsql:...')` uses the not yet deprecated alias.
 */
final class PostgresNotifyWaiter implements IdleWaiter
{
    // A burst of transactions can leave many notifications queued
    private const MAX_DRAINED_NOTIFICATIONS = 1000;

    // A ready task that could not be reserved means lock contention: retry
    // shortly instead of spinning at a zero timeout
    private const MIN_CONTENTION_WAIT_MS = 20;

    // Waking exactly at the deadline can find the clock just before it
    private const DUE_MARGIN_MS = 5;

    private ?PDO $listener = null;

    /**
     * @var null|Closure(int):bool
     */
    private ?Closure $notifier = null;

    private bool $stopping = false;

    /**
     * @param PDO|Closure():PDO $connection A dedicated listener connection. Use a
     *  closure to reconnect after a connection loss.
     * @param int $fallbackMs Longest wait without a notification
     * @param int $sliceMs Longest blocking slice, which bounds signal latency
     */
    public function __construct(
        private readonly PostgresTaskStore $store,
        private readonly PDO|Closure $connection,
        private readonly int $fallbackMs = 5000,
        private readonly int $sliceMs = 250,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if ($fallbackMs < 1 || $fallbackMs > 2_147_483_647) {
            throw new InvalidArgumentException('The notification fallback must be between 1 and 2147483647 milliseconds');
        }
        if ($sliceMs < 1 || $sliceMs > 2_147_483_647) {
            throw new InvalidArgumentException('The notification wait slice must be between 1 and 2147483647 milliseconds');
        }
    }

    /**
     * Creates a waiter that opens its own listener connection from a DSN. Use
     * `PDO::connect()` on PHP 8.4+ so `getNotify()` is not deprecated.
     *
     * @param array<int,mixed> $options PDO options
     */
    public static function fromDsn(
        PostgresTaskStore $store,
        string $dsn,
        ?string $user = null,
        #[\SensitiveParameter]
        ?string $password = null,
        array $options = [],
        int $fallbackMs = 5000,
        int $sliceMs = 250,
        LoggerInterface $logger = new NullLogger(),
    ): self {
        $connection = static function () use ($dsn, $user, $password, $options): PDO {
            if (PHP_VERSION_ID >= 80_400) {
                // PDO::connect() returns the driver-specific subclass; calling it
                // through reflection keeps the minimum supported PHP version
                // analysable without pretending the method exists on 8.3.
                $connect = new ReflectionMethod(PDO::class, 'connect');
                $pdo = $connect->invoke(null, $dsn, $user, $password, $options);
                if (!$pdo instanceof PDO) {
                    throw new RuntimeException('PDO::connect() did not return a connection');
                }
                return $pdo;
            }
            return new PDO($dsn, $user, $password, $options);
        };
        return new self($store, $connection, $fallbackMs, $sliceMs, $logger);
    }

    public function start(): void
    {
        if ($this->listener !== null) {
            return;
        }
        $pdo = $this->connection instanceof PDO ? $this->connection : ($this->connection)();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new RuntimeException('The notification waiter needs a PostgreSQL PDO connection');
        }
        $pdo->exec('LISTEN ' . $this->store->notificationChannel());
        $this->listener = $pdo;
        $this->notifier = $this->notifierFor($pdo);
        if (!$this->store->notificationsEnabled()) {
            $this->log('warning', 'The PostgreSQL notification trigger is missing: run notificationSchema() to reduce task latency');
        }
    }

    public function wait(array $queues, int $limitMs): bool
    {
        if ($this->stopping) {
            return false;
        }
        $listening = true;
        try {
            $this->start();
        } catch (PDOException $error) {
            if ($this->listener !== null) {
                // The trigger check failed on the store connection, not the listener
                throw $error;
            }
            // A listener failure must not stop the worker: the store still
            // works, and the next wait retries the subscription.
            $this->log('warning', 'The PostgreSQL listener is unavailable, polling until it reconnects', ['exception' => $error]);
            $listening = false;
        }
        if ($this->stopping) {
            return false;
        }
        if ($listening) {
            try {
                // A notification may have arrived between the empty reservation
                // and this call: reading it now keeps that wakeup from being lost.
                if ($this->drain()) {
                    return true;
                }
            } catch (PDOException $error) {
                return $this->lost($error);
            }
        }
        if ($limitMs <= 0) {
            return false;
        }

        // A store failure must propagate: it is not a lost listener
        $waitMs = $this->fallbackMs;
        $dueMs = $this->store->nextDueInMs($queues);
        if ($dueMs !== null) {
            $waitMs = min($waitMs, max(self::MIN_CONTENTION_WAIT_MS, $dueMs + self::DUE_MARGIN_MS));
        }
        $waitMs = min($waitMs, $limitMs);
        if ($waitMs <= 0) {
            return false;
        }

        $deadline = microtime(true) + ($waitMs / 1000);
        while (microtime(true) < $deadline) {
            if ($this->stopping) {
                return false;
            }
            $remainingMs = max(0, (int) ceil(($deadline - microtime(true)) * 1000));
            $sliceMs = min($this->sliceMs, $remainingMs);
            if ($sliceMs <= 0) {
                break;
            }
            if (!$listening) {
                // No subscription yet: poll at the fallback pace, not in a spin
                usleep($sliceMs * 1000);
                continue;
            }
            try {
                if ($this->receive($sliceMs)) {
                    $this->drain();
                    return true;
                }
            } catch (PDOException $error) {
                return $this->lost($error);
            }
        }
        return false;
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    public function close(): void
    {
        $listener = $this->listener;
        $this->listener = null;
        $this->notifier = null;
        $this->stopping = false;
        if ($listener === null) {
            return;
        }
        try {
            $listener->exec('UNLISTEN *');
        } catch (Throwable $error) {
            $this->log('debug', 'The PostgreSQL listener was already closed', ['exception' => $error]);
        }
    }

    /**
     * A lost listener must not stop the worker: the next wait reconnects and the
     * fallback still covers the gap. Returning true forces an immediate poll.
     */
    private function lost(PDOException $error): bool
    {
        $this->log('warning', 'The PostgreSQL listener connection was lost, polling until it reconnects', ['exception' => $error]);
        $this->close();
        return true;
    }

    /**
     * @phpstan-impure A signal handler can call stop() during this block
     */
    private function receive(int $timeoutMs): bool
    {
        $notifier = $this->notifier;
        if ($notifier === null) {
            throw new RuntimeException('The notification waiter is not started');
        }
        return $notifier($timeoutMs);
    }

    /**
     * Picks the driver-specific method without naming `Pdo\Pgsql`, which does
     * not exist on PHP 8.3. On PHP 8.5 the generic alias is deprecated, so a
     * plain PDO must be replaced by `PDO::connect()`.
     *
     * @return Closure(int):bool
     */
    private function notifierFor(PDO $pdo): Closure
    {
        if (method_exists($pdo, 'getNotify')) {
            $method = 'getNotify';
        } elseif (PHP_VERSION_ID < 80_500) {
            $method = 'pgsqlGetNotify';
        } else {
            throw new RuntimeException('PHP 8.5 needs a Pdo\Pgsql listener, create it with PDO::connect()');
        }
        if (!is_callable([$pdo, $method])) {
            throw new RuntimeException('PHP 8.5 needs a Pdo\Pgsql listener, create it with PDO::connect()');
        }
        $notify = Closure::fromCallable([$pdo, $method]);
        return static fn(int $timeoutMs): bool => $notify(PDO::FETCH_ASSOC, $timeoutMs) !== false;
    }

    /**
     * @phpstan-impure A signal handler can call stop() between two reads
     */
    private function drain(): bool
    {
        $received = false;
        for ($i = 0; $i < self::MAX_DRAINED_NOTIFICATIONS; $i++) {
            if (!$this->receive(0)) {
                return $received;
            }
            $received = true;
        }
        return $received;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        try {
            $this->logger->log($level, $message, $context);
        } catch (Throwable) {
            // A listener warning must not change the worker outcome
            return;
        }
    }
}
