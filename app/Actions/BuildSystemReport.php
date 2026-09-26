<?php

declare(strict_types=1);

namespace App\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * How the running application is holding up, measured at the moment of asking.
 *
 * Every probe here is live — a query against the database, a round trip
 * through the cache, a look at the disk — rather than a figure some scheduler
 * wrote down earlier, because a health page that reports the last time things
 * were fine is worse than no page. Each probe is also contained: one that
 * throws becomes a failed check on the page instead of a 500 in place of it,
 * since the moment somebody opens this screen is the moment something is
 * most likely to be broken.
 *
 * What it does not do is keep history. There is no metrics store behind this
 * and adding one is a dependency decision, not a page; the queue backlog, the
 * failed jobs and the table sizes are the signals that already exist in the
 * database, so they are the ones read.
 */
final readonly class BuildSystemReport
{
    /** Failed jobs listed on the page; the rest are counted. */
    private const int FAILED_JOB_LIMIT = 20;

    /** Below this share of free disk the check turns red. */
    private const float DISK_WARNING_RATIO = 0.1;

    /**
     * The tables worth watching grow, in the order they are listed.
     *
     * Named rather than discovered: a list of every framework table sorted by
     * size answers a question nobody asked, while these are the ones whose
     * growth is either the product working or something going wrong.
     */
    private const array WATCHED_TABLES = [
        'users',
        'challenge_runs',
        'user_challenge_progress',
        'quiz_attempts',
        'drone_photos',
        'creem_orders',
        'creem_subscriptions',
        'sessions',
        'jobs',
        'failed_jobs',
    ];

    public function __construct(
        private Application $app,
        private Cache $cache,
        private FailedJobProviderInterface $failer,
    ) {}

    /**
     * @return array{
     *     environment: array{name: string, environment: string, debug: bool, maintenance: bool, php: string, laravel: string, timezone: string, drivers: array<string, string>},
     *     checks: array<int, array{name: string, ok: bool, detail: string, latencyMs: float|null}>,
     *     runtime: array{memoryPeak: int, memoryLimit: string, loadAverage: array<int, float>|null, opcache: bool, diskFree: int|null, diskTotal: int|null},
     *     queue: array{connection: string, pending: int|null, oldestPendingSeconds: int|null, failed: int|null},
     *     failedJobs: array<int, array{id: string, connection: string, queue: string, job: string, exception: string, failedAt: string|null}>,
     *     tables: array<int, array{name: string, rows: int|null, size: int|null}>,
     *     sessions: array{last5Minutes: int|null, last60Minutes: int|null},
     * }
     */
    public function handle(): array
    {
        $disk = $this->disk();

        return [
            'environment' => $this->environment(),
            'checks' => [
                $this->databaseCheck(),
                $this->cacheCheck(),
                $this->queueCheck(),
                $this->diskCheck($disk),
            ],
            'runtime' => [
                'memoryPeak' => memory_get_peak_usage(true),
                'memoryLimit' => ini_get('memory_limit'),
                'loadAverage' => $this->loadAverage(),
                'opcache' => function_exists('opcache_get_status') && is_array(@opcache_get_status(false)),
                'diskFree' => $disk['free'],
                'diskTotal' => $disk['total'],
            ],
            'queue' => $this->queue(),
            'failedJobs' => $this->failedJobs(),
            'tables' => $this->tables(),
            'sessions' => [
                'last5Minutes' => $this->sessionsSince(CarbonImmutable::now()->subMinutes(5)),
                'last60Minutes' => $this->sessionsSince(CarbonImmutable::now()->subHour()),
            ],
        ];
    }

    /**
     * @return array{name: string, environment: string, debug: bool, maintenance: bool, php: string, laravel: string, timezone: string, drivers: array<string, string>}
     */
    private function environment(): array
    {
        return [
            'name' => config()->string('app.name'),
            'environment' => $this->app->environment(),
            'debug' => config()->boolean('app.debug'),
            'maintenance' => $this->app->isDownForMaintenance(),
            'php' => PHP_VERSION,
            'laravel' => $this->app->version(),
            'timezone' => config()->string('app.timezone'),
            'drivers' => [
                'Database' => DB::connection()->getDriverName(),
                'Cache' => $this->configString('cache.default'),
                'Queue' => $this->configString('queue.default'),
                'Session' => $this->configString('session.driver'),
                'Mail' => $this->configString('mail.default'),
                'Photos disk' => $this->configString('filesystems.photo_disk'),
            ],
        ];
    }

    /**
     * @return array{name: string, ok: bool, detail: string, latencyMs: float|null}
     */
    private function databaseCheck(): array
    {
        return $this->probe('Database', function (): string {
            DB::select('select 1');

            return sprintf('%s connection answering', DB::connection()->getDriverName());
        });
    }

    /**
     * A full round trip rather than a read: a cache that accepts writes and
     * returns nothing is the failure worth catching, and only reading back
     * what was just written catches it.
     *
     * @return array{name: string, ok: bool, detail: string, latencyMs: float|null}
     */
    private function cacheCheck(): array
    {
        return $this->probe('Cache', function (): string {
            $key = 'admin:health:'.Str::random(12);
            $value = Str::random(16);

            $this->cache->put($key, $value, 10);
            $read = $this->cache->get($key);
            $this->cache->forget($key);

            throw_if($read !== $value, RuntimeException::class, 'A value written to the cache did not come back.');

            return sprintf('%s store round trip', $this->configString('cache.default'));
        });
    }

    /**
     * Healthy while nothing has failed in the last day. Older failures are
     * still listed below, but a job that fell over last month is a chore, not
     * an outage.
     *
     * @return array{name: string, ok: bool, detail: string, latencyMs: float|null}
     */
    private function queueCheck(): array
    {
        try {
            $recent = $this->failedTable()?->where('failed_at', '>=', CarbonImmutable::now()->subDay())->count();
        } catch (Throwable $throwable) {
            return ['name' => 'Queue', 'ok' => false, 'detail' => $this->firstLine($throwable->getMessage()), 'latencyMs' => null];
        }

        if ($recent === null) {
            return ['name' => 'Queue', 'ok' => true, 'detail' => 'Failures are not stored in the database', 'latencyMs' => null];
        }

        return [
            'name' => 'Queue',
            'ok' => $recent === 0,
            'detail' => $recent === 0
                ? 'No failed jobs in the last 24 hours'
                : sprintf('%d failed %s in the last 24 hours', $recent, Str::plural('job', $recent)),
            'latencyMs' => null,
        ];
    }

    /**
     * @param  array{free: int|null, total: int|null}  $disk
     * @return array{name: string, ok: bool, detail: string, latencyMs: float|null}
     */
    private function diskCheck(array $disk): array
    {
        if ($disk['free'] === null || $disk['total'] === null || $disk['total'] === 0) {
            return ['name' => 'Disk space', 'ok' => true, 'detail' => 'Not reported by this host', 'latencyMs' => null];
        }

        return [
            'name' => 'Disk space',
            'ok' => $disk['free'] / $disk['total'] >= self::DISK_WARNING_RATIO,
            'detail' => sprintf('%s free of %s', $this->bytes($disk['free']), $this->bytes($disk['total'])),
            'latencyMs' => null,
        ];
    }

    /**
     * Run a check, timing it, and turn any exception into a failed result.
     *
     * @param  callable(): string  $check
     * @return array{name: string, ok: bool, detail: string, latencyMs: float|null}
     */
    private function probe(string $name, callable $check): array
    {
        $started = hrtime(true);

        try {
            $detail = $check();
        } catch (Throwable $throwable) {
            return ['name' => $name, 'ok' => false, 'detail' => $this->firstLine($throwable->getMessage()), 'latencyMs' => null];
        }

        return [
            'name' => $name,
            'ok' => true,
            'detail' => $detail,
            'latencyMs' => round((hrtime(true) - $started) / 1_000_000, 2),
        ];
    }

    /**
     * @return array{connection: string, pending: int|null, oldestPendingSeconds: int|null, failed: int|null}
     */
    private function queue(): array
    {
        $connection = $this->configString('queue.default');
        $pending = null;
        $oldest = null;

        if (config('queue.connections.'.$connection.'.driver') === 'database') {
            try {
                $jobs = $this->jobsTable($connection);
                $pending = (clone $jobs)->count();
                $available = (clone $jobs)->min('available_at');
                $oldest = is_numeric($available) ? max(0, CarbonImmutable::now()->getTimestamp() - (int) $available) : null;
            } catch (Throwable) {
                $pending = null;
            }
        }

        try {
            $failed = $this->failer instanceof CountableFailedJobProvider ? $this->failer->count() : null;
        } catch (Throwable) {
            $failed = null;
        }

        return [
            'connection' => $connection,
            'pending' => $pending,
            'oldestPendingSeconds' => $oldest,
            'failed' => $failed,
        ];
    }

    /**
     * @return array<int, array{id: string, connection: string, queue: string, job: string, exception: string, failedAt: string|null}>
     */
    private function failedJobs(): array
    {
        try {
            $rows = $this->failedTable()
                ?->orderByDesc('failed_at')
                ->orderByDesc('id')
                ->limit(self::FAILED_JOB_LIMIT)
                ->get(['uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']);
        } catch (Throwable) {
            return [];
        }

        if ($rows === null) {
            return [];
        }

        return $rows->map(function (stdClass $record): array {
            $row = fluent($record);
            $payload = json_decode((string) $row->string('payload'), true);
            $failedAt = $row->get('failed_at');

            return [
                'id' => (string) $row->string('uuid'),
                'connection' => (string) $row->string('connection'),
                'queue' => (string) $row->string('queue'),
                'job' => is_array($payload) && is_string($payload['displayName'] ?? null) ? $payload['displayName'] : 'Unknown job',
                'exception' => $this->firstLine((string) $row->string('exception')),
                'failedAt' => is_string($failedAt) ? CarbonImmutable::parse($failedAt)->toIso8601String() : null,
            ];
        })->values()->all();
    }

    /**
     * Row counts for the watched tables, with sizes where the driver reports
     * them. MySQL reports data plus index length; SQLite reports nothing
     * without an extension, and null says so.
     *
     * @return array<int, array{name: string, rows: int|null, size: int|null}>
     */
    private function tables(): array
    {
        $sizes = [];

        try {
            foreach (Schema::getTables() as $table) {
                if (! is_array($table) || ! is_string($table['name'] ?? null)) {
                    continue;
                }

                $sizes[$table['name']] = is_numeric($table['size'] ?? null) ? (int) $table['size'] : null;
            }
        } catch (Throwable) {
            $sizes = [];
        }

        $tables = [];

        foreach (self::WATCHED_TABLES as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }

            try {
                $rows = DB::table($name)->count();
            } catch (Throwable) {
                $rows = null;
            }

            $tables[] = ['name' => $name, 'rows' => $rows, 'size' => $sizes[$name] ?? null];
        }

        return $tables;
    }

    /**
     * Distinct sessions active since a moment, guests included. Null unless
     * sessions are stored in the database, where this can count them.
     */
    private function sessionsSince(CarbonImmutable $since): ?int
    {
        if (config('session.driver') !== 'database') {
            return null;
        }

        try {
            return DB::table($this->configString('session.table', 'sessions'))
                ->where('last_activity', '>=', $since->getTimestamp())
                ->count();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{free: int|null, total: int|null}
     */
    private function disk(): array
    {
        $path = storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        return [
            'free' => is_float($free) ? (int) $free : null,
            'total' => is_float($total) ? (int) $total : null,
        ];
    }

    /**
     * @return array<int, float>|null
     */
    private function loadAverage(): ?array
    {
        if (! function_exists('sys_getloadavg')) {
            return null;
        }

        $load = sys_getloadavg();

        return is_array($load) ? array_map(static fn (float $value): float => round($value, 2), $load) : null;
    }

    /**
     * The failed jobs table, when failures are kept in one.
     */
    private function failedTable(): ?Builder
    {
        $driver = $this->configString('queue.failed.driver');

        if (! in_array($driver, ['database', 'database-uuids'], true)) {
            return null;
        }

        return $this->connection($this->configString('queue.failed.database'))
            ->table($this->configString('queue.failed.table', 'failed_jobs'));
    }

    private function jobsTable(string $queueConnection): Builder
    {
        $database = config('queue.connections.'.$queueConnection.'.connection');
        $table = config('queue.connections.'.$queueConnection.'.table');

        return $this->connection(is_string($database) ? $database : '')
            ->table(is_string($table) ? $table : 'jobs');
    }

    private function connection(string $name): ConnectionInterface
    {
        return $name === '' ? DB::connection() : DB::connection($name);
    }

    private function configString(string $key, string $default = ''): string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function firstLine(string $text): string
    {
        return Str::limit(mb_trim(strtok($text, "\n") ?: $text), 300);
    }

    private function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

        return sprintf('%.1f %s', $bytes / (1024 ** $power), $units[$power]);
    }
}
