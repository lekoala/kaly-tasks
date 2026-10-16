# Kaly Tasks

A durable SQL task queue for [Kaly](https://github.com/lekoala/kaly) tasks.

Kaly defines the task model and the `Kaly\Task\TaskDispatcher` contract. This
package is one optional engine: it stores tasks in PostgreSQL or SQLite and
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

## Guarantees

- **At least once.** A task runs until it succeeds, fails for good, or exceeds
  its attempts. After a crash, a task may run again: tasks must be idempotent.
- **Leases with owner tokens.** A reserved task belongs to one worker for the
  lease duration. Once the lease expires another worker may take it, and the
  late worker can no longer settle it. A lease does not stop a live worker, so
  two runs may exceptionally overlap.
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

## Where the queue lives

The table can live in the application database or in a database of its own.
The connection given to the dispatcher decides what `dispatch()` guarantees:

| Dispatcher connection | When `dispatch()` returns |
|---|---|
| The application connection, inside a transaction | The task is written in that transaction: durable with the commit, gone with a rollback (transactional outbox) |
| A dedicated queue database | The task is durable immediately, independently of the business transaction |

With a dedicated database, the business commit and the dispatch are two
separate writes: dispatch after the commit, and let tasks tolerate missing or
changed data.

The worker always uses its own connection to the queue database.

## Stores

Both stores give the same guarantees and pass the same test suite. Use the
database the application already has: on the application connection, a
dispatch joins the business transaction.

| | `PostgresTaskStore` | `SqliteTaskStore` |
|---|---|---|
| Hosts | Workers on any number of hosts | The application and its workers on one host |
| Reservation | `FOR UPDATE SKIP LOCKED`: workers never wait for each other | One short `UPDATE … RETURNING`: reservations queue up, task runs do not |
| Clock | The database clock | The host clock, in whole seconds |
| Requires | PostgreSQL | SQLite 3.38 through pdo_sqlite |

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

The store needs two operations, behind `Kaly\Tasks\Connection\Connection`:

| Library | Connection |
|---|---|
| PDO | `new PdoConnection($pdo)`, or `new PdoConnection(fn(): PDO => ...)` for a lazy handle, which must return the handle running the transaction |
| Doctrine DBAL / ORM | `new DoctrineConnection($entityManager->getConnection())` |
| Cycle | `new CycleConnection($databaseManager->database())` |

Statements run on the given connection, inside its current transaction. An
EntityManager or a Cycle unit of work opened per operation does not matter: the
transaction belongs to the shared connection.

## Setup

Create the table from your migrations:

```php
foreach ($store->schema() as $statement) {
    // execute $statement
}
```

`PostgresTaskStore` takes a table name, optionally schema-qualified
(`queue.tasks`). `SqliteTaskStore` takes a plain table name.

Register the persistable types and wire the dispatcher in the composition root:

```php
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
    ->set(TaskDispatcher::class, static fn(ContainerInterface $c) => new DatabaseTaskDispatcher(
        new PostgresTaskStore(new DoctrineConnection($c->get(EntityManagerInterface::class)->getConnection())),
        $c->get(TaskRegistry::class),
    )));
```

A persistable task has only public promoted constructor parameters, typed
`int`, `float`, `string`, `bool`, `array` (of scalars, null and arrays) or a
backed enum. Registration checks it.

Stored payloads are a public contract until every stored task has been
processed or migrated. Adding an optional parameter is compatible; renaming,
retyping or removing one (or an enum case) is not: register the new shape
under a new name and keep the old one until its tasks are drained.

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
$store->retryFailed($id);         // run a failed task again, with fresh attempts
$store->purge(7 * 24 * 3600, 30 * 24 * 3600); // delete done tasks after a week, failed ones after a month
```

Done and failed tasks are kept until purged: their deduplication keys stay
active for the retention period. Without the second argument, failed tasks are
kept until you retry or delete them.

## Tests

```bash
docker compose up -d   # PostgreSQL on port 55432
composer test
```

Every suite runs against both stores. The SQLite tests use temporary files
and always run; set `KALY_TASKS_PGSQL_DSN` to another database, or empty to
skip the PostgreSQL tests.
