# Changelog

## Unreleased

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
- `Kaly\Tasks\Worker` runs tasks through the Kaly Injector, recovers expired
  leases, recycles itself and handles signals and hard timeouts with
  ext-pcntl. The attempt budget is checked before a task is rebuilt, and the
  rebuild runs under the timeout. A failure, retry or completion settled after
  the lease was lost is logged as a warning.
- Connections for PDO, Doctrine DBAL and Cycle. Every statement runs on the
  primary: the Cycle write driver, the primary of a Doctrine primary/replica
  connection.
