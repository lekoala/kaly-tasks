# Results

Measured on 2026-11-19 with PHP 8.3.21 and PostgreSQL 18.6, both local, one
worker unless stated, via `php bench/run.php`. These numbers show the shape of
the change, not a promise for a remote or pooled database: a remote connection
adds network latency to every reservation and notification round trip.

## Isolated arrivals

Twelve tasks committed 1.13 s apart, 16 s run.

| Mode | p50 | p95 | Reservations | Statements |
| --- | ---: | ---: | ---: | ---: |
| `sleepMs: 1000` (default) | 627.09 ms | 959.26 ms | 28 | 41 |
| `sleepMs: 100` (baseline) | 36.54 ms | 95.75 ms | 158 | 171 |
| `notify` | **1.27 ms** | **2.25 ms** | 25 | 52 |

## Idle worker

Twelve seconds without a task.

| Mode | Reservations | Statements | Statements/s |
| --- | ---: | ---: | ---: |
| `sleepMs: 1000` | 12 | 13 | 1.08 |
| `sleepMs: 100` | 110 | 111 | 9.25 |
| `notify` | 3 | 8 | 0.67 |

The waiter's fallback poll dominates the idle cost: 0.67 statements per second
for a 5 s fallback, against 1.08 for the default 1 s sleep.

## Deferred task

One task committed with `available_at = now() + 1.5 s`.

| Mode | Start after due | Start after commit |
| --- | ---: | ---: |
| `sleepMs: 1000` | 516.27 ms late | 2016.27 ms |
| `notify` | **13.66 ms late** | 1513.66 ms |

The due query caps the wait at the next `available_at`, so a deferred task is
not held until the 5 s fallback.

## Burst

Thirty commits at once.

| Mode | p50 | p95 | Drain | Reservations | Statements |
| --- | ---: | ---: | ---: | ---: | ---: |
| `sleepMs: 1000` | 28.05 ms | 37.29 ms | 64.37 ms | 40 | 71 |
| `notify` | 17.17 ms | 24.68 ms | 54.83 ms | 34 | 69 |

Draining queued notifications keeps a burst at roughly one reservation per
task. Without it, each commit wakes the worker once more; the regression test
in `tests/PostgresNotifyTest.php` bounds that.

## Four workers, forty commits

| Mode | p50 | p95 | Reservations | Statements |
| --- | ---: | ---: | ---: | ---: |
| `sleepMs: 1000` | 19.33 ms | 26.18 ms | 80 | 124 |
| `notify` | **0.46 ms** | **1.73 ms** | 119 | 185 |

Notification fan-out costs more reservations than polling here, because four
listeners wake for the same commits. Draining keeps it around three per task
rather than one per notification per worker (about 1500 in the spike without
draining).

## Trigger overhead

One thousand direct inserts, with and without the notification trigger:

| Trigger | p50 per insert | p95 per insert |
| --- | ---: | ---: |
| No | 764.8 µs | 1778.1 µs |
| Yes | 690.0 µs | 903.1 µs |

The difference is within run-to-run noise: the trigger adds no measurable
insert cost at this scale.
