<?php

declare(strict_types=1);

namespace App\Actions;

use App\Concerns\DescribesSystem;
use App\Queries\QueueState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * How the running application is holding up, measured at the moment of asking.
 *
 * Every probe here is live — a query against the database, a round trip
 * through the cache, a look at the disk — rather than a figure some scheduler
 * wrote down earlier, because a health page that reports the last time things
 * were fine is worse than no page. Each probe is also contained: see
 * {@see RunHealthChecks} for the checks, which turn anything they throw into a
 * failed check. The figures around the checks fall back to null the same way,
 * and report what threw so the cause outlives the page.
 *
 * What it does not do is keep history. There is no metrics store behind this
 * and adding one is a dependency decision, not a page; the queue backlog, the
 * failed jobs and the table sizes are the signals that already exist in the
 * database, so they are the ones read — the queue's through {@see QueueState}.
 */
final readonly class BuildSystemReport
{
    use DescribesSystem;

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
        'sessions',
        'jobs',
        'failed_jobs',
    ];

    public function __construct(
        private Application $app,
        private RunHealthChecks $checks,
        private QueueState $queue,
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
            'checks' => $this->checks->handle($disk),
            'runtime' => [
                'memoryPeak' => memory_get_peak_usage(true),
                'memoryLimit' => ini_get('memory_limit'),
                'loadAverage' => $this->loadAverage(),
                'opcache' => function_exists('opcache_get_status') && is_array(@opcache_get_status(false)),
                'diskFree' => $disk['free'],
                'diskTotal' => $disk['total'],
            ],
            'queue' => $this->queue->backlog(),
            'failedJobs' => $this->queue->failedJobs(),
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
     * Row counts for the watched tables, with sizes where the driver reports
     * them. MySQL reports data plus index length; SQLite reports nothing
     * without an extension, and null says so.
     *
     * @return array<int, array{name: string, rows: int|null, size: int|null}>
     */
    private function tables(): array
    {
        $names = array_values(array_filter(
            self::WATCHED_TABLES,
            static fn (string $name): bool => Schema::hasTable($name),
        ));

        $sizes = $this->tableSizes();
        $rows = $this->rowCounts($names);

        return array_map(static fn (string $name): array => [
            'name' => $name,
            'rows' => $rows[$name] ?? null,
            'size' => $sizes[$name] ?? null,
        ], $names);
    }

    /**
     * @return array<string, int|null>
     */
    private function tableSizes(): array
    {
        $sizes = [];

        try {
            foreach (Schema::getTables() as $table) {
                if (! is_array($table) || ! is_string($table['name'] ?? null)) {
                    continue;
                }

                $sizes[$table['name']] = is_numeric($table['size'] ?? null) ? (int) $table['size'] : null;
            }
        } catch (Throwable $throwable) {
            report($throwable);

            return [];
        }

        return $sizes;
    }

    /**
     * Every table's row count in one statement, a scalar subquery apiece,
     * rather than a round trip per table.
     *
     * Which means they fail together: a table that cannot be counted leaves
     * every count on the page unknown, and reported, rather than only its own.
     * That is the price of one query instead of one per table.
     *
     * @param  list<string>  $names
     * @return array<string, int|null>
     */
    private function rowCounts(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $query = DB::query();

        foreach ($names as $name) {
            $query->selectSub(DB::table($name)->selectRaw('count(*)'), $name);
        }

        try {
            $row = fluent($query->first());
        } catch (Throwable $throwable) {
            report($throwable);

            return [];
        }

        $counts = [];

        foreach ($names as $name) {
            $count = $row->get($name);
            $counts[$name] = is_numeric($count) ? (int) $count : null;
        }

        return $counts;
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
        } catch (Throwable $throwable) {
            report($throwable);

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
            return null; // @codeCoverageIgnore
        }

        $load = sys_getloadavg();

        return is_array($load) ? array_map(static fn (float $value): float => round($value, 2), $load) : null;
    }
}
