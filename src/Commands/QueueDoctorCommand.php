<?php

namespace QueueDoctor\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class QueueDoctorCommand extends Command
{
    protected $signature = 'queue:doctor {--max-age=3600 : Age in seconds above which a backlog on the default connection is treated as an error}';

    protected $description = 'Diagnose queue health and web-tier store reachability: assert the default dispatch connection is actually being drained by a worker, surface backlogs that indicate a connection mismatch or a dead worker, and verify the cache/session backends every request depends on are reachable.';

    public function handle(): int
    {
        $default = config('queue.default');
        $defaultDriver = config("queue.connections.{$default}.driver");

        $this->line("Default connection: <info>{$default}</info> (driver: {$defaultDriver})");
        $this->line('Failed jobs: <info>'.$this->failedCount().'</info>');
        $this->newLine();

        // Pending backlog per configured connection. A healthy fast queue sits near
        // zero; a growing/old backlog on the *default* connection is the signature of
        // either a dead worker or a worker draining a different connection.
        $sizes = $this->backlogByConnection();

        $rows = [];
        foreach ($sizes as $name => $info) {
            $rows[] = [
                $name === $default ? "{$name} (default)" : $name,
                $info['driver'],
                $info['size'] ?? 'n/a',
                $info['oldest'] ? $info['oldest']->diffForHumans() : '—',
            ];
        }
        $this->table(['Connection', 'Driver', 'Pending', 'Oldest job'], $rows);

        // Best-effort: what connection are the running workers actually draining?
        $workerConnections = $this->detectWorkerConnections();
        if ($workerConnections === null) {
            $this->line('Workers: <comment>could not inspect process list from this context</comment>');
        } elseif ($workerConnections === []) {
            $this->line('Workers: <comment>no queue:work process visible</comment>');
        } else {
            $this->line('Workers draining: <info>'.implode(', ', $workerConnections).'</info>');
        }
        $this->newLine();

        // Web-tier stores: the cache + session backends that StartSession and the cache
        // repository hit on *every* request. A Redis outage here 500s every route long
        // before any queue symptom shows — the queue checks above are blind to it.
        $storeChecks = $this->checkBackingStores();
        $storeRows = [];
        foreach ($storeChecks as $check) {
            $storeRows[] = [
                $check['component'],
                $check['backend'],
                $check['ok'] ? '<info>ok</info>' : '<error>FAIL</error>',
                $check['detail'],
            ];
        }
        $this->table(['Web-tier store', 'Backend', 'Status', 'Detail'], $storeRows);
        $this->newLine();

        $queueStatus = $this->verdict($default, $sizes, $workerConnections, (int) $this->option('max-age'));

        // A web-critical store being down is more severe than any queue backlog: report
        // it last (most visible) and force a failing exit regardless of queue health.
        $storeFailures = array_filter($storeChecks, fn (array $check): bool => ! $check['ok']);
        if ($storeFailures !== []) {
            $this->newLine();
            foreach ($storeFailures as $failure) {
                $this->error("WEB-TIER STORE DOWN: {$failure['component']} backend [{$failure['backend']}] is unreachable — {$failure['detail']}");
            }
            $this->line('This 500s every route via StartSession/cache. Restore the backend, or fail over (e.g. CACHE_STORE=database, SESSION_DRIVER=cookie) until it recovers.');

            return self::FAILURE;
        }

        return $queueStatus;
    }

    /**
     * Probe the stores the web tier depends on per request — the default cache store and
     * the session backend. Each probe is best-effort and never throws; a caught exception
     * (e.g. "SSL: Connection reset by peer" from a downed Redis) becomes a FAIL row.
     *
     * @return list<array{component: string, backend: string, ok: bool, detail: string}>
     */
    protected function checkBackingStores(): array
    {
        return [
            $this->probeCacheStore(),
            $this->probeSessionStore(),
        ];
    }

    /**
     * @return array{component: string, backend: string, ok: bool, detail: string}
     */
    private function probeCacheStore(): array
    {
        $store = config('cache.default');
        $driver = config("cache.stores.{$store}.driver");
        $backend = "{$store} ({$driver})";

        try {
            $key = 'queue-doctor:probe:'.uniqid();
            Cache::store($store)->put($key, '1', 5);
            $ok = Cache::store($store)->get($key) === '1';
            Cache::store($store)->forget($key);

            return ['component' => 'cache', 'backend' => $backend, 'ok' => $ok, 'detail' => $ok ? 'round-trip ok' : 'value did not round-trip'];
        } catch (Throwable $e) {
            return ['component' => 'cache', 'backend' => $backend, 'ok' => false, 'detail' => $this->shortError($e)];
        }
    }

    /**
     * @return array{component: string, backend: string, ok: bool, detail: string}
     */
    private function probeSessionStore(): array
    {
        $driver = (string) config('session.driver');

        try {
            switch ($driver) {
                case 'redis':
                    $connection = config('session.connection') ?: 'default';
                    Redis::connection($connection)->ping();

                    return ['component' => 'session', 'backend' => "redis ({$connection})", 'ok' => true, 'detail' => 'reachable'];

                case 'database':
                    $connection = config('session.connection');
                    $table = config('session.table', 'sessions');
                    DB::connection($connection)->table($table)->limit(1)->exists();

                    return ['component' => 'session', 'backend' => "database ({$table})", 'ok' => true, 'detail' => 'reachable'];

                case 'cookie':
                case 'array':
                    return ['component' => 'session', 'backend' => $driver, 'ok' => true, 'detail' => 'no external backend'];

                default:
                    return ['component' => 'session', 'backend' => $driver, 'ok' => true, 'detail' => 'not probed'];
            }
        } catch (Throwable $e) {
            return ['component' => 'session', 'backend' => $driver, 'ok' => false, 'detail' => $this->shortError($e)];
        }
    }

    private function shortError(Throwable $e): string
    {
        $message = trim($e->getMessage());
        $message = $message === '' ? $e::class : $message;

        return mb_strlen($message) > 120 ? mb_substr($message, 0, 117).'...' : $message;
    }

    /**
     * @param  array<string, array{driver: string, size: ?int, oldest: ?CarbonImmutable}>  $sizes
     * @param  list<string>|null  $workerConnections
     */
    private function verdict(string $default, array $sizes, ?array $workerConnections, int $maxAge): int
    {
        $defaultSize = $sizes[$default]['size'] ?? 0;
        $defaultOldest = $sizes[$default]['oldest'] ?? null;

        // A worker is visibly running but none of them watch the default connection —
        // the exact mismatch where jobs dispatch to one broker and are drained from another.
        if ($workerConnections && ! in_array($default, $workerConnections, true)) {
            $this->error("MISMATCH: jobs dispatch to '{$default}', but the running worker(s) drain [".implode(', ', $workerConnections).'].');
            $this->line("Fix: run 'queue:work {$default}' (or set QUEUE_CONNECTION to a connection a worker is draining).");

            return self::FAILURE;
        }

        if ($defaultSize > 0 && $defaultOldest && $defaultOldest->lt(CarbonImmutable::now()->subSeconds($maxAge))) {
            $this->error("STALLED: '{$default}' has {$defaultSize} pending job(s), oldest {$defaultOldest->diffForHumans()} — the worker is not draining it.");
            $this->line("Fix: ensure a 'queue:work {$default}' worker is running and healthy.");

            return self::FAILURE;
        }

        if ($defaultSize > 0) {
            $this->warn("'{$default}' has {$defaultSize} pending job(s). Fine if transient; investigate if it keeps growing.");

            return self::SUCCESS;
        }

        $this->info("Queue healthy: default connection '{$default}' has no backlog.");

        return self::SUCCESS;
    }

    /**
     * Pending job count + oldest job age for each configured connection.
     *
     * @return array<string, array{driver: string, size: ?int, oldest: ?CarbonImmutable}>
     */
    private function backlogByConnection(): array
    {
        $result = [];

        foreach (array_keys(config('queue.connections', [])) as $name) {
            $driver = config("queue.connections.{$name}.driver");

            // Inert drivers never hold a backlog worth reporting.
            if (in_array($driver, ['sync', 'null'], true)) {
                continue;
            }

            $result[$name] = [
                'driver' => $driver,
                'size' => $this->sizeOf($name),
                'oldest' => $this->oldestJob($name, $driver),
            ];
        }

        return $result;
    }

    private function sizeOf(string $connection): ?int
    {
        try {
            $queue = config("queue.connections.{$connection}.queue", 'default');

            return app('queue')->connection($connection)->size($queue);
        } catch (Throwable) {
            return null;
        }
    }

    private function oldestJob(string $connection, ?string $driver): ?CarbonImmutable
    {
        // Only the database driver exposes a cheap, portable way to read job age.
        if ($driver !== 'database') {
            return null;
        }

        try {
            $table = config("queue.connections.{$connection}.table", 'jobs');
            $dbConnection = config("queue.connections.{$connection}.connection");

            $row = DB::connection($dbConnection)->table($table)->orderBy('available_at')->first();

            return $row ? CarbonImmutable::createFromTimestamp($row->available_at) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function failedCount(): int|string
    {
        try {
            return app('queue.failer')->count();
        } catch (Throwable) {
            return 'n/a';
        }
    }

    /**
     * Best-effort discovery of which connection(s) running queue workers are draining,
     * by parsing the process list. Returns null when the process list is unavailable.
     *
     * @return list<string>|null
     */
    protected function detectWorkerConnections(): ?array
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        $ps = @shell_exec('ps -eo args 2>/dev/null');
        if (! is_string($ps) || $ps === '') {
            return null;
        }

        $connections = [];
        foreach (preg_split('/\R/', $ps) as $line) {
            if (! preg_match('/\bqueue:(work|listen)\b(.*)$/', $line, $m)) {
                continue;
            }

            // First non-flag token after the command is the connection name; absent
            // means the worker is using the framework default.
            $tokens = preg_split('/\s+/', trim($m[2])) ?: [];
            $connection = config('queue.default');
            foreach ($tokens as $token) {
                if ($token === '' || str_starts_with($token, '-')) {
                    continue;
                }
                $connection = $token;
                break;
            }

            $connections[$connection] = true;
        }

        return array_keys($connections);
    }
}
