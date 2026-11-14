# Changelog

## 0.6.0 - 2026-11-14

- **Breaking:** depend on `lekoala/kaly-contracts` `^1.0` instead of
  `lekoala/kaly`. The interfaces keep their namespace (`Kaly\Task\*`), so no
  import or binding changes, and Kaly Tasks no longer follows Kaly minor
  releases. The contracts conflict with Kaly below 0.7. In a Kaly application,
  first update the application's `composer.json` constraints to
  `lekoala/kaly: ^0.7` and `lekoala/kaly-tasks: ^0.6`, then upgrade both together
  with `composer update lekoala/kaly lekoala/kaly-tasks --with-all-dependencies`.
  `composer update` alone does not change the application's version constraints.
- **Breaking:** `Worker` takes an invoker closure instead of `Kaly\Di\Injector`:
  `Closure(callable&object): mixed`, which calls each decoded task with its
  services. With kaly-di, replace `$injector` by `$injector->invoke(...)`.
  Without a container, tasks without services can use
  `static fn(object $task) => $task()`. The invoker is required.
- **Breaking:** `lekoala/kaly-di` is no longer required; it moves to
  `require-dev` and `suggest`. Applications using the Injector keep it through
  their own Kaly dependency or require it directly.
- **Breaking:** `PermanentFailure` and `RetryLater` extend `RuntimeException`
  instead of `Kaly\Ex`, so task failures do not depend on the Kaly framework.
  Catch them by their own class.

## 0.5.0 - 2026-11-14

- Add `Kaly\Tasks\PermanentFailure`: thrown by a running task, it fails the task
  at once, whatever attempts remain.
- Add `Kaly\Tasks\RetryLater`: thrown by a running task, it delays the next
  attempt by the given seconds. The delay replaces the backoff and is not capped
  by `maxDelay`; the task still fails once it exhausts `maxAttempts`. Delays
  outside 0–2147483647 are rejected when the exception is built.
- Add an optional execution wrapper, `Worker::__construct(around: ...)`, called
  with a `Kaly\Tasks\TaskRun` (id, name, queue, attempt, max attempts, decoded
  task) and the invocation. It must run the task exactly once; any exception
  it throws counts as a task failure. It wraps the invocation only, not
  reservation, decoding or settlement.
- **Breaking:** `ReservedTask` carries the task `queue`, after `name`. Custom
  stores must return it from `reserve()`. No schema change.
- Worker log context includes the queue.
- Document retention as a setup step, long tasks and timeout limits, rolling
  deploys of payload changes, and recurring tasks dispatched from several hosts.

## 0.4.0 - 2026-11-13

- Add `WorkerOptions::$prioritizeQueues`. When enabled, the worker reserves
  from each queue in the given order and takes the first available task, so
  earlier queues have strict priority and can starve later ones. Disabled by
  default: each store keeps its current selection policy. No store, SQL or
  schema change.
- A logger that throws no longer changes a task outcome, stops the worker loop
  or prevents the hard timeout from ending the process. Store failures still
  propagate.
- **Breaking:** `TaskStore` requires `stats(?string $queue = null): TaskStats`;
  custom stores must implement it.
- Add `Kaly\Tasks\Store\TaskStats` and queue statistics on all three stores:
  ready, scheduled (retries included), running (expired leases included), failed
  and the oldest pending availability. One aggregate statement per call using
  the store clock, with dates floored to whole UTC seconds. No schema change.
- Document dispatch failure outcomes, lease expiry versus loss of ownership,
  and a versioned-reminder recipe for moving scheduled work without a cancel
  or reschedule API.

## 0.3.0 - 2026-10-26

- **Breaking:** require Kaly `^0.6` for the `ScheduledTaskDispatcher` capability.
  `DatabaseTaskDispatcher` implements it and remains usable as `TaskDispatcher`.
- **Breaking:** `TaskStore::push()` accepts `?DateTimeImmutable $notBefore = null`;
  update custom stores. Deduplicated insertions preserve the existing deadline
  and payload. Scheduling requires no schema migration or additional scheduler.
- Add optional `?DateTimeImmutable $notBefore` to database task dispatch for
  scheduled execution on PostgreSQL, MySQL and SQLite. Dates are normalized to
  UTC; PostgreSQL and MySQL preserve microseconds, while SQLite rounds up to
  whole seconds. SQL time remains authoritative for queue operations.
- Add `MysqlTaskStore` for MySQL 8.0.16+ and Aurora MySQL v3 writer endpoints,
  with short `FOR UPDATE SKIP LOCKED` reservations, UTC database timestamps,
  verbatim payloads and SHA-256 deduplication keys. Queue names and owners are
  limited to 255 bytes. Add MySQL integration, concurrency and transaction
  tests, and a MySQL service to Compose and CI. Reservations poll queues in
  shuffled order through the pending index, preserving availability ordering
  within each queue without locking all ready tasks during a filesort.
- Add `TransactionalConnection`, implemented by PDO, Doctrine and Cycle.
  Its callback runs on a pinned primary connection and commits before returning;
  existing transactions are refused for reservation. Dispatch still joins the
  application's transaction. Existing `Connection` implementations remain valid
  for PostgreSQL and SQLite; MySQL requires `TransactionalConnection`.
- Cycle parameters preserve integer and null types, including MySQL `LIMIT`
  parameters.

## 0.2.0 - 2026-10-23

- **Breaking:** both stores reject NUL bytes in task names, queue names,
  deduplication keys and owner tokens with `InvalidArgumentException` before
  SQL runs. This prevents silent PostgreSQL truncation from dropping distinct
  tasks as duplicates, changing routing or accepting a different owner token.
  SQLite users with NUL bytes in these fields must migrate them before
  upgrading. NUL characters in JSON payloads remain supported.
- Storage failures while settling an undecodable task now propagate and stop
  the worker, without attempting another settlement.
- Decoding rejects non-finite floats, including overflowed JSON numbers in
  nested arrays. Invalid enum backing types consistently raise
  `UnexpectedValueException`.

- **Breaking:** task payloads must be JSON objects, even without constructor
  parameters. Such tasks now encode as `{}`; migrate any stored `[]` payloads
  to `{}` before upgrading.
- **Breaking:** `TaskStore` now requires `schema()` and `deleteFailed()`;
  custom implementations must add them. `failed()` rows include `created_at`
  and `finished_at` as integer Unix timestamps in whole seconds.
- Retry policies reject non-finite multipliers and delays above the SQL integer
  limit. Worker options reject leases above that limit and empty queue names;
  stores also reject empty queue names at reservation.
- Failed tasks can be individually deleted, freeing their deduplication key.
  Documented deduplication results, error retention on retry and running tests
  without PostgreSQL.

## 0.1.0 — 2026-10-16

- `Kaly\Tasks\DatabaseTaskDispatcher` implements `Kaly\Task\TaskDispatcher` on
  a SQL store: a task dispatched on the application connection joins the
  current transaction (transactional outbox), a dedicated database keeps the
  queue out of the application schema.
- `Kaly\Tasks\TaskRegistry` whitelists persistable task types under stable
  names and stores their constructor data as validated JSON. Decoding checks
  every stored value against its declared type, whatever the `strict_types`
  mode, and arrays nested deeper than the JSON limit, recursive ones included,
  are rejected at dispatch. `deduplicateBy` names a non-nullable `string` or
  `int` parameter.
- `Kaly\Tasks\Store\PostgresTaskStore` reserves with `FOR UPDATE SKIP LOCKED`,
  with leases, owner tokens, bounded exponential retries, deduplication keys,
  failed tasks and purge. Payloads are stored as `JSON`, not `JSONB`, so the
  stored text is kept verbatim (key order, `\u0000`). Out-of-range leases,
  delays, limits and retentions are rejected before reaching SQL, and so are
  table names too long for distinct index names. Errors are stored as valid
  UTF-8, bounded to 4 KiB.
- `Kaly\Tasks\Store\SqliteTaskStore` gives the same guarantees on one host:
  a reservation is a single `UPDATE … RETURNING`, so workers run tasks in
  parallel while SQLite serializes the short writes. Requires SQLite 3.38
  through pdo_sqlite: `DoctrineConnection` refuses the `sqlite3` driver, as
  ext-sqlite3 runs a statement again when its rows are fetched (PHP bugs
  #64531 and #81376).
- `Kaly\Tasks\Worker` runs tasks through the Kaly Injector, recovers expired
  leases, recycles itself and handles signals and hard timeouts with
  ext-pcntl. The attempt budget is checked before a task is rebuilt, and the
  rebuild runs under the timeout. A failure, retry or completion settled after
  the lease was lost is logged as a warning.
- Connections for PDO, Doctrine DBAL and Cycle. Every statement runs on the
  primary: the Cycle write driver, the primary of a Doctrine primary/replica
  connection.
