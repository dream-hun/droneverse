<?php

declare(strict_types=1);

namespace App\Actions;

use App\Concerns\DescribesSystem;
use App\Queries\QueueState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The pass-or-fail checks at the top of the system report.
 *
 * Each is live — a query against the database, a round trip through the
 * cache, a look at the disk — and each is contained: one that throws becomes
 * a failed check carrying the first line of what it threw, instead of a 500 in
 * place of the page, since the moment somebody opens this screen is the
 * moment something is most likely to be broken.
 */
final readonly class RunHealthChecks
{
    use DescribesSystem;

    /** Below this share of free disk the check turns red. */
    private const float DISK_WARNING_RATIO = 0.1;

    public function __construct(
        private Cache $cache,
        private QueueState $queue,
    ) {}

    /**
     * @param  array{free: int|null, total: int|null}  $disk
     * @return array<int, array{name: string, ok: bool, detail: string, latencyMs: float|null}>
     */
    public function handle(array $disk): array
    {
        return [
            $this->databaseCheck(),
            $this->cacheCheck(),
            $this->queueCheck(),
            $this->diskCheck($disk),
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
     * still listed on the page, but a job that fell over last month is a
     * chore, not an outage.
     *
     * @return array{name: string, ok: bool, detail: string, latencyMs: float|null}
     */
    private function queueCheck(): array
    {
        try {
            $recent = $this->queue->failuresSince(CarbonImmutable::now()->subDay());
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
            return ['name' => 'Disk space', 'ok' => true, 'detail' => 'Not reported by this host', 'latencyMs' => null]; // @codeCoverageIgnore
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

    private function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

        return sprintf('%.1f %s', $bytes / (1024 ** $power), $units[$power]);
    }
}
