# Listening benchmark

Compares the fixed worker sleep with the opt-in PostgreSQL `LISTEN/NOTIFY`
waiter, using the real `Worker`, `PostgresTaskStore` and
`PostgresNotifyWaiter`.

```bash
docker compose up -d
mkdir -p bench/runs
php bench/run.php > bench/runs/results.json
```

`KALY_TASKS_BENCH_DSN` overrides the default DSN. Each scenario recreates its
own `kaly_tasks_bench` table, so never point it at a real queue.

The runner records, for each scenario:

- end-to-end latency between commit and task start, p50/p95/max;
- lateness against `available_at`, which matters for deferred tasks;
- reservation calls, total statements and CPU seconds per worker;
- duplicates and missing runs, to catch a lost wakeup.

Scenarios cover isolated arrivals, an idle worker, a task scheduled 1.5 s
ahead, a burst of 30 commits, and four workers on 40 commits. `poll-100` is the
lower-latency polling baseline users already have; `notify-5000` uses a five
second fallback.

Results depend on the host, PostgreSQL settings and whether the connection is
local or remote. Run it on the deployment topology you care about.
