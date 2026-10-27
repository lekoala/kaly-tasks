# Kaly Tasks

A durable SQL task queue for [Kaly](https://github.com/lekoala/kaly) tasks.

Kaly defines the task model and its dispatcher contracts. This
package is one optional engine: it stores tasks in PostgreSQL, MySQL or SQLite and
runs them in supervised worker processes. A durable task queue without a queue
server. Application code does not change:

```php
$this->tasks->dispatch(new GeneratePdf($documentId));
```

A task stays a plain invocable object, with its data in the constructor and its
services in `__invoke()`:

```php
final readonly class GeneratePdf
{
    public function __construct(
        public string $documentId,
    ) {}

    public function __invoke(PdfGenerator $generator): void
    {
        $generator->generate($this->documentId);
    }
}
```

It is a specialized engine, not a messaging framework: no attributes, no
handler registry, no events, no transports.

## Scheduling

```php
$tasks->dispatch(new DeliverSms($deliveryId), notBefore: $clock->now()->modify('+10 seconds'));
$tasks->dispatch(new SendReminder($appointmentId), notBefore: $reminderDate);
```

`notBefore` is an optional `DateTimeImmutable` lower bound. Without it, dispatch
is immediate; a past date is immediately eligible. A future task cannot be
reserved before its deadline, but polling and worker load may make it run later.
`stopWhenEmpty` stops when nothing is due, even if future tasks remain queued.

The application expresses business dates with its injected PSR-20 clock. The
stores keep SQL time for availability comparisons, leases, retries and retention;
application and database clocks must be synchronized for relative business delays.
Dates are normalized at the storage boundary: PostgreSQL stores timezone-aware
instants and MySQL stores UTC `DATETIME(6)`, both preserving microseconds. SQLite
keeps integer epoch seconds and rounds fractional deadlines up to the next second,
so precision never makes a task eligible early. No schema migration is needed.

For portable dates, use UTC years 1000 through 9999, the range of MySQL
`DATETIME(6)`. Dates outside this range are unsupported; use strict SQL mode so
MySQL rejects out-of-range dates instead of coercing them to a zero date.
PostgreSQL dates before the common era are also unsupported by this date codec.

Deduplication preserves the existing task, including its payload and deadline:
a second dispatch with the same key neither advances nor postpones it. Retries
use their normal SQL-clock delay after execution, independently of the original
deadline. A scheduled insertion joins the application transaction as usual.

Requires Kaly 0.6 for `Kaly\Task\ScheduledTaskDispatcher`. Services that schedule
work depend on this interface; services that only submit tasks can keep
`Kaly\Task\TaskDispatcher`. The direct dispatcher offers the minimal contract.
Kaly's recording dispatcher supports scheduling with an injectable test clock.

### Moving a scheduled reminder

Use a business version to supersede reminders without changing stored tasks:

```php
final readonly class SendAppointmentReminder
{
    public function __construct(
        public string $appointmentId,
        public int $version,
        public string $deduplicationKey,
    ) {}

    public function __invoke(AppointmentRepository $appointments, ReminderSender $sender): void
    {
        $appointment = $appointments->find($this->appointmentId);
        if ($appointment === null || $appointment->cancelled) {
            return;
        }
        if ($appointment->version !== $this->version) {
            return; // superseded by a later change
        }
        $sender->send($appointment);
    }
}

$registry->register(
    'appointments.remind',
    SendAppointmentReminder::class,
    deduplicateBy: 'deduplicationKey',
);

// On booking or rescheduling, ideally in the same application transaction:
$tasks->dispatch(
    new SendAppointmentReminder(
        $appointment->id,
        $appointment->version,
        "{$appointment->id}:{$appointment->version}",
    ),
    notBefore: $appointment->startsAt->modify('-1 day'),
);
```

Increment the business version for every relevant change: date, location,
recipient and cancellation. The handler explicitly checks deletion and
cancellation too: matching a version does not establish that an appointment is
still active. An obsolete reminder still runs at its original deadline, consumes
one execution slot and returns without sending.

The version check is not atomic with sending: if the appointment changes during
an external call, an outdated reminder may still be sent. Decide whether that
race is acceptable for notifications; business writes need suitable concurrency
control. Use a stable provider idempotency key for the reminder operation to
prevent repeated external effects on retries; it does not make the version check
atomic with sending.

Deduplicating by `appointmentId` alone cannot move a reminder because it preserves
the existing deadline. Versioned keys provide a recipe without a cancellation or
rescheduling API.

## Guarantees

- **At least once.** A task runs until it succeeds, fails for good, or exceeds
  its attempts. After a crash, a task may run again: tasks must be idempotent.
- **Leases with owner tokens.** A reserved task belongs to one worker for the
  lease duration. Settlement requires ownership, not a live lease: a worker
  finishing after its deadline can still record its result until `recover()`
  invalidates the reservation. Recovery clears the owner, and a new reservation
  gets a new token, so the late worker's settlement then changes nothing.
  A lease does not stop a live worker, so two runs may exceptionally overlap.
  Keeping `timeout < lease` helps prevent overlap; it is not a condition for
  accepting settlement.
- **Bounded retries.** Exponential backoff per task type, then `failed`. A
  task abandoned too often (worker crashes, timeouts) fails without running.
- **Whitelisted JSON payloads.** Only registered types are stored and rebuilt,
  under stable names. No PHP serialization, no class name in the database.
- **Deduplication.** A task type may declare a deduplication parameter: a task
  whose key is already stored is skipped, whatever its status, until it is
  purged. A failed task is not replaced by a new dispatch: inspect it and
  `retryFailed()`.

Deduplicating a task is not deduplicating its effects. If a worker calls an
external API and crashes before settling the task, the call is repeated.
Carry a stable operation key in the task and use it for the provider
idempotency, or reconcile the external state before acting.

### When a dispatch fails

| Situation | What is known | Response |
|---|---|---|
| Validation rejection before SQL: unregistered task, unencodable payload, NUL in an identifier or an out-of-range value | No insertion was attempted | Correct the input and dispatch again |
| A definite SQL rejection, such as an invalid type or a `NOT NULL` constraint violation | That insertion was rejected | Correct the cause and dispatch again; respect the transaction's error state |
| Lost connection, ambiguous `COMMIT` or a failure after sending the statement | The task may have been stored | Do not infer that the task is absent; retry with the same deduplication key while it is retained, or reconcile the stored state |

Retrying with the same deduplication key prevents duplicate stored tasks while
that key is retained. It does not guarantee exactly-once external effects.
`purge()` and `deleteFailed()` free retained keys. An application transaction
must commit before the task becomes durable; commit failures may themselves
have an uncertain outcome.

PDO, Doctrine and Cycle cannot reliably classify every failure, so there is no
`OutcomeUnknownException` hierarchy. An arbitrary exception is not proof that
nothing was written. See [Where the queue lives](#where-the-queue-lives): with a
dedicated queue database, business commit and dispatch are separate writes.

## Where the queue lives

The table can live in the application database or in a database of its own.
The connection given to the dispatcher decides what `dispatch()` guarantees:

| Dispatcher connection | When `dispatch()` returns |
|---|---|
| The application connection, inside a transaction | The task is written in that transaction: durable with the commit, gone with a rollback (transactional outbox) |
| A dedicated queue database | The task is durable immediately, independently of the business transaction |

With a dedicated database, the business commit and the dispatch are two
separate writes. For reliable publication, write a transactional application
outbox and publish it idempotently: a process can crash between the business
commit and a direct dispatch. Tasks must also tolerate changed business data.

The worker always uses its own connection to the queue database.

## Stores

All three stores give the same guarantees and pass the same test suite. Use the
database the application already has: on the application connection, a
dispatch joins the business transaction.

| | `PostgresTaskStore` | `SqliteTaskStore` | `MysqlTaskStore` |
|---|---|---|---|
| Hosts | Any number of hosts | One host | Any number of hosts |
| Reservation | One statement with `SKIP LOCKED` | One `UPDATE ... RETURNING` | Short transaction with `SKIP LOCKED` |
| Clock | Database | Host, in whole seconds | Database, UTC microseconds |
| Requires | PostgreSQL | SQLite 3.38 through pdo_sqlite | MySQL 8.0.16+ / Aurora MySQL v3, InnoDB |

The SQLite store does not support ext-sqlite3 (Doctrine's `sqlite3` driver):
it runs a statement again when its rows are fetched, so a reservation would
claim a second task. `DoctrineConnection` refuses that driver. See the PHP
bugs:

- [#64531](https://bugs.php.net/bug.php?id=64531): `SQLite3Result::fetchArray()`
  may run the query again;
- [#81376](https://bugs.php.net/bug.php?id=81376): it explicitly affects
  `UPDATE … RETURNING`.

SQLite serializes writes, not tasks: a worker holds no lock while its task
runs, so several workers process tasks in parallel. Contention only shows with
many concurrent writes or long application transactions. The store leaves the
connection settings to you:

- a file on a local disk, never on a network share, in WAL mode
  (`PRAGMA journal_mode = WAL`);
- a busy timeout on every connection (pdo_sqlite waits 60s by default,
  `PDO::ATTR_TIMEOUT`);
- `PRAGMA synchronous = FULL` if a power loss must not lose the last
  dispatched tasks (WAL with `NORMAL` may);
- backups taken with `VACUUM INTO` or the SQLite backup API, not by copying
  the file.

## Connections

The base store connection needs two operations, behind `Kaly\Tasks\Connection\Connection`:

| Library | Connection |
|---|---|
| PDO | `new PdoConnection($pdo)`, or `new PdoConnection(fn(): PDO => ...)` for a lazy handle, which must return the handle running the transaction |
| Doctrine DBAL / ORM | `new DoctrineConnection($entityManager->getConnection())` |
| Cycle | `new CycleConnection($databaseManager->database())` |

Statements run on the given connection, inside its current transaction. An
EntityManager or a Cycle unit of work opened per operation does not matter: the
transaction belongs to the shared connection.

MySQL also requires `TransactionalConnection`, implemented by all three adapters.
Its `transaction()` callback receives a connection pinned to the primary for the
whole transaction, commits before returning and rolls back on failure. Existing
transactions are refused: a reservation must release its locks before executing
the task. Dispatch still joins an existing application transaction normally.

## MySQL and Aurora

```php
$pdo = new PDO($writerDsn, $user, $password);
$store = new MysqlTaskStore(new PdoConnection($pdo));
```

Requires MySQL 8.0.16+ with InnoDB, or Aurora MySQL v3. Use the writer endpoint
for dispatch, reservation and maintenance. Reservations select with
`FOR UPDATE SKIP LOCKED`, update the owner and lease, then commit before the
worker invokes the task. The worker uses its own connection without an existing
transaction. Database errors propagate and the supervisor restarts the worker;
an uncertain commit is recovered through the lease, without running the task
on the failed reservation.

MySQL polls queues individually through the `pending` index to avoid a filesort
locking every ready task across several queues. Queue order is shuffled for each
reservation; ordering by availability and ID is preserved within each queue.
There is no global FIFO guarantee across queues.

Times are UTC `DATETIME(6)` values computed by the database, independently of
the session time zone. Payloads use `LONGTEXT` to preserve their exact JSON text.
Deduplication indexes a SHA-256 hash of the task name and key, compared bytewise,
with the same retention as the other stores (subject to SHA-256 collision risk).
Queue names and owner tokens are limited to 255 bytes. Use strict SQL mode to
reject oversized values. MySQL table names are plain lowercase identifiers of
at most 64 characters. Run `schema()` through migrations before dispatching;
MySQL DDL implicitly commits.

When publishing a business change and a task atomically, share the same physical
connection and transaction. Otherwise use a transactional application outbox:
a dispatch after a business commit can be lost if the process crashes between
the two writes.

## Setup

Create the table from your migrations:

```php
foreach ($store->schema() as $statement) {
    // execute $statement
}
```

`PostgresTaskStore` takes a table name, optionally schema-qualified
(`queue.tasks`). `SqliteTaskStore` takes a plain table name.

Register task types and bind both contracts to the same dispatcher instance:

```php
use Kaly\Task\ScheduledTaskDispatcher;
use Kaly\Task\TaskDispatcher;
use Kaly\Tasks\Connection\DoctrineConnection;
use Kaly\Tasks\DatabaseTaskDispatcher;
use Kaly\Tasks\RetryPolicy;
use Kaly\Tasks\Store\PostgresTaskStore;
use Kaly\Tasks\TaskRegistry;

$app->configure(fn(Definitions $di) => $di
    ->set(TaskRegistry::class, static fn() => (new TaskRegistry())
        ->register('documents.generate-pdf', GeneratePdf::class)
        ->register('support.process-webhook', ProcessWebhook::class,
            queue: 'support',
            retry: new RetryPolicy(maxAttempts: 8, delay: 30),
            deduplicateBy: 'eventId',
        ))
    ->set(ScheduledTaskDispatcher::class, static fn(ContainerInterface $c) => new DatabaseTaskDispatcher(
        new PostgresTaskStore(new DoctrineConnection($c->get(EntityManagerInterface::class)->getConnection())),
        $c->get(TaskRegistry::class),
    ))
    ->alias(TaskDispatcher::class, ScheduledTaskDispatcher::class));
```

A persistable task has only public promoted constructor parameters, typed
`int`, `float`, `string`, `bool`, `array` (of scalars, null and arrays) or a
backed enum. Registration checks it. Payloads are JSON objects, including `{}`
for tasks without constructor parameters; JSON lists such as `[]` are rejected.
Float values must be finite, including inside nested arrays. Decoding checks
enum backing types strictly and reports invalid payloads with `UnexpectedValueException`.

Stored payloads are a public contract until every stored task has been
processed or migrated. Adding an optional parameter is compatible; renaming,
retyping or removing one (or an enum case) is not: register the new shape
under a new name and keep the old one until its tasks are drained.

`dispatch()` follows Kaly's `void` contract. To observe whether deduplication
skipped an insertion, use `$store->push($registry->encode($task))`: it returns
`true` for a new task and `false` for an existing key.

Both stores reject NUL bytes (`\0`) in task names, queue names, deduplication
keys and owner tokens with `InvalidArgumentException` before executing SQL.
This prevents PostgreSQL text parameters from being silently truncated,
changing routing or treating distinct tasks as duplicates. NUL characters in
task data remain supported as JSON escapes, unless used as a deduplication key.

## Worker

Kaly has no console: a worker is a small script of the application.

```php
// bin/worker.php
$app = App::create(dirname(__DIR__))->boot();
$container = $app->container();

$worker = new Worker(
    new PostgresTaskStore(new PdoConnection(new PDO($queueDsn))),
    $container->get(TaskRegistry::class),
    $container->get(Injector::class),
    $container->get(LoggerInterface::class),
);
$worker->run(new WorkerOptions(queues: ['default', 'support']));
```

Run it under a supervisor (systemd, supervisord, a container restart policy).
The worker is crash-only and recycles itself:

- it exits normally after `maxTasks`, `maxRuntime` or `memoryLimit`, and the
  supervisor starts a fresh process;
- a store failure (lost connection) propagates and ends the process;
- with ext-pcntl, SIGTERM finishes the current task then exits, and a task
  running beyond `timeout` ends the process. Its lease expires and the task is
  retried. Keep `timeout` above your external call timeouts and below `lease`.

Without ext-pcntl (Windows), there is no hard timeout: the lease must cover the
longest run.

The worker opens no container scope per task. Shared services must be
re-entrant, and stateful resources (EntityManager, unit of work) are opened by
the operation that uses them, as for a request. A task never receives an HTTP
context: identity and authorization travel in its data and are checked again.

With PostgreSQL, leases and delays use the database clock, so workers on
several hosts agree.

## Maintenance

```php
$store->failed(limit: 50);        // inspect failed tasks
$store->deleteFailed($id);        // delete a failed task and free its deduplication key
$store->retryFailed($id);         // run a failed task again, with fresh attempts
$store->purge(7 * 24 * 3600, 30 * 24 * 3600); // delete done tasks after a week, failed ones after a month
```

`failed()` returns rows with `id`, `queue`, `name`, `payload`, `attempts`,
`error`, `created_at` and `finished_at`. Both dates are Unix timestamps in whole
seconds. `retryFailed()` preserves the previous error until the task succeeds
or another failure replaces it.

Done and failed tasks are kept until purged: their deduplication keys stay
active for the retention period. Without the second argument, failed tasks are
kept until you retry or delete them.

```php
$stats = $store->stats();           // whole table
$mailStats = $store->stats('mail'); // one queue

$stats->ready;              // pending, due now
$stats->scheduled;          // pending, due later, retries included
$stats->running;            // reserved, expired leases included
$stats->failed;             // failed for good
$stats->earliestPendingAt;  // ?DateTimeImmutable, UTC: oldest pending availability
```

Each call uses one aggregate `SELECT` on the writer. Its five values share the
statement's view under the connection's isolation rules; they may be stale as
soon as it returns, and separate calls do not share a snapshot. Availability
comparisons use the database clock. `earliestPendingAt` is floored to whole UTC
seconds, or `null` without pending tasks. When work is ready, this date is in the
past or now and shows how long the oldest pending work has been waiting.
`stats(null)` covers the whole table; an empty queue name or one containing NUL
bytes is rejected.

Statistics use the existing status indexes without a schema change. The global
minimum may scan pending entries across queues; the cost grows with pending
work, even though completed tasks can be excluded. A queue filter allows an
indexed lookup for its oldest pending availability. Measure against your active
queue size before polling frequently. These statistics support application
dashboards and supervision; they do not provide history, durations, per-type
metrics or a console command.

## Tests

```bash
docker compose up -d   # PostgreSQL on port 55432, MySQL on port 53306
composer test
```

Every suite runs against all three stores. The SQLite tests use temporary files
and always run; set `KALY_TASKS_PGSQL_DSN` to another database, or empty to
skip the PostgreSQL tests. `KALY_TASKS_MYSQL_DSN` defaults to
`mysql:host=127.0.0.1;port=53306;dbname=kaly_tasks;user=kaly;password=kaly;charset=utf8mb4`;
set it empty to skip MySQL. The MySQL test engine reads the user/password fields
from this test DSN and passes them to PDO separately. MySQL tests include the
store contract through PDO, Doctrine and Cycle, worker concurrency, signals,
transaction rollback, locked rows and stale owners.

To run only the local SQLite tests:

```bash
KALY_TASKS_PGSQL_DSN="" KALY_TASKS_MYSQL_DSN="" composer test
```

The default DSNs expect Docker on ports 55432 (PostgreSQL) and 53306 (MySQL).
If a database is configured but unreachable, the tests fail rather than silently
skipping it.
