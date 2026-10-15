# Changelog

## Unreleased

- **Breaking:** `deduplicateBy` must name a non-nullable `string` or `int`
  parameter: a null key silently disabled deduplication.
- **Breaking:** the schema adds a partial index on failed tasks. Existing
  tables need it:
  `CREATE INDEX kaly_tasks_failed ON kaly_tasks (finished_at) WHERE status = 'failed'`.
- `TaskRegistry::decode()` checks every stored value against its declared type
  itself, whatever the `strict_types` mode. A whole float stored as an integer
  decodes as a float.
- `PostgresTaskStore` rejects out-of-range leases, delays, limits and
  retentions instead of passing them to SQL.
- The worker logs a warning whenever a failure or retry is settled after the
  lease was lost, not only a completion.
- `PdoConnection` reports a failed `prepare()` in silent error mode.
- The store suite runs against PDO, Doctrine DBAL and Cycle, and signal
  handling and hard timeouts are tested in a real worker process (ext-pcntl).

## 0.1.0 - 2026-10-16

- `Kaly\Tasks\DatabaseTaskDispatcher` implements `Kaly\Task\TaskDispatcher` on
  a SQL store: a task dispatched on the application connection joins the
  current transaction (transactional outbox), a dedicated database keeps the
  queue out of the application schema.
- `Kaly\Tasks\TaskRegistry` whitelists persistable task types under stable
  names and stores their constructor data as validated JSON.
- `Kaly\Tasks\Store\PostgresTaskStore` reserves with `FOR UPDATE SKIP LOCKED`,
  with leases, owner tokens, bounded exponential retries, deduplication keys,
  failed tasks and purge.
- `Kaly\Tasks\Worker` runs tasks through the Kaly Injector, recovers expired
  leases, recycles itself and handles signals and hard timeouts with ext-pcntl.
- Connections for PDO, Doctrine DBAL and Cycle.
