<?php

declare(strict_types=1);

namespace Kaly\Tasks;

/**
 * Waits while a worker is completely idle, instead of sleeping for a fixed
 * delay. A waiter replaces `WorkerOptions::$sleepMs` in the worker loop.
 *
 * A waiter only decides when to look again: the task table remains the source
 * of truth. Missing a signal may make a task late, never lose it. A waiter
 * therefore always keeps a fallback poll, even when its signal is reliable.
 */
interface IdleWaiter
{
    /**
     * Subscribes before the worker's first reservation, so no task inserted
     * afterwards can be missed. Calling it again is a no-op.
     */
    public function start(): void;

    /**
     * Blocks until work may be available or the limit expires. It is called
     * only after the worker found no task in any of its queues, and it must
     * not return before checking for signals that arrived during that
     * reservation.
     *
     * @param list<string> $queues The queues the worker polls
     * @param int $limitMs The longest the worker may stay idle, in milliseconds
     * @return bool True when a signal arrived, false on timeout
     */
    public function wait(array $queues, int $limitMs): bool;

    /**
     * Asks a current wait to return promptly, so a signal does not have to
     * wait for the fallback delay. It must not throw and may run from a signal
     * handler.
     */
    public function stop(): void;

    /**
     * Releases the subscription. It must never throw: it runs while another
     * error may already be propagating.
     */
    public function close(): void;
}
