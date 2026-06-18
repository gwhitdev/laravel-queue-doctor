# Laravel Queue Doctor

A single Artisan command — `php artisan queue:doctor` — that catches the three queue/infra failure modes Laravel itself stays quiet about:

1. **Worker/connection mismatch** — jobs dispatch to one connection (say `redis`) but the only running worker drains another (say `database`). Jobs pile up forever and *nothing errors*. They just never run.
2. **Stalled backlog** — a connection has old pending jobs and no worker is draining them (dead worker, wrong supervisor config, scaled-to-zero).
3. **Web-tier store outage** — your cache or session backend (often Redis) is unreachable. This is the nastiest one: it `500`s **every single request** through `StartSession`/the cache repository, long before any *queue* symptom appears — and a queue health check that only looks at the queue is blind to it.

## Why this exists

This was extracted from a production Laravel app after a multi-hour outage with a frustratingly simple root cause: jobs were dispatched to Redis, but the worker was started as `queue:work database`. No exception, no failed job, no log line — work simply vanished into a queue nobody was draining. A second incident came from the *other* direction: Redis (backing both session and cache) dropped its connection, and `StartSession` turned every route into a `500` while the queue looked perfectly healthy.

`queue:doctor` is the check we wished we'd had: it asserts the connection you *dispatch* to is the one a worker is actually *draining*, and it round-trips the cache/session backends every request depends on.

## Installation

```bash
composer require garethwhitleychard/laravel-queue-doctor
```

The service provider is auto-discovered. No config or migration to publish.

## Usage

```bash
php artisan queue:doctor
```

Example output:

```
Default connection: redis (driver: redis)
Failed jobs: 0

+----------------------+----------+---------+------------+
| Connection           | Driver   | Pending | Oldest job |
+----------------------+----------+---------+------------+
| redis (default)      | redis    | 0       | —          |
| database             | database | 0       | —          |
+----------------------+----------+---------+------------+
Workers draining: redis

+----------------+----------------+--------+---------------+
| Web-tier store | Backend        | Status | Detail        |
+----------------+----------------+--------+---------------+
| cache          | redis (redis)  | ok     | round-trip ok |
| session        | redis (default)| ok     | reachable     |
+----------------+----------------+--------+---------------+

Queue healthy: default connection 'redis' has no backlog.
```

### Options

| Option | Default | Description |
|---|---|---|
| `--max-age` | `3600` | Age in seconds above which a backlog on the **default** connection is treated as a hard failure rather than a transient warning. |

### Exit codes

`queue:doctor` exits **non-zero** on a mismatch, a stalled backlog, or an unreachable web-tier store — so it drops straight into CI, a deploy gate, or a cron-driven healthcheck:

```bash
# fail a deploy / alert if the queue or its backing stores are unhealthy
php artisan queue:doctor || notify-oncall "queue:doctor failed"
```

## How the worker detection works

Worker discovery parses the process list (`ps -eo args`) for `queue:work`/`queue:listen` and reads the connection argument. It is **best-effort**: in environments where the process list isn't visible (some containers, restricted hosts) it reports "could not inspect process list" and skips the mismatch verdict rather than producing a false alarm. The backlog and store checks still run.

## Failing over a downed store

When `queue:doctor` reports `WEB-TIER STORE DOWN`, the fastest recovery that keeps the app serving is to move off the broken backend until it recovers:

```dotenv
CACHE_STORE=database
SESSION_DRIVER=cookie
```

## Testing

```bash
composer install
vendor/bin/pest
```

## License

MIT. See [LICENSE](LICENSE).
