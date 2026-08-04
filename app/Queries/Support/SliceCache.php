<?php

declare(strict_types=1);

namespace App\Queries\Support;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * A generation-keyed cache for slices of a read model.
 *
 * Extracted from App\Queries\Leaderboard when a second read model needed the
 * same policy. It is deliberately a collaborator rather than a base class:
 * each query still chooses its own prefix, its own TTL and which of its
 * slices are worth serialising, and those are the parts that differ. What is
 * shared is the mechanism, and every line of it is a defect that was found
 * the hard way — which is the argument against a second copy of it.
 *
 * Four properties it exists to hold:
 *
 * 1. **The generation counter is seeded before it is bumped.** Only some
 *    cache drivers treat `increment` on an absent key as counting up from
 *    zero; the database and memcached stores return false and write nothing,
 *    so bumping a generation no write had ever created left the model pinned
 *    at generation zero and every invalidation silently did nothing. `add` is
 *    the atomic create-if-absent that closes it, and creating the counter
 *    *is* the first bump, so it never double-counts.
 *
 * 2. **Cached values are wrapped rather than stored bare.** A slice can
 *    legitimately be `null` — a pilot who has not flown has no standing and
 *    no curve — and a bare null is indistinguishable from a miss. Unwrapped,
 *    exactly those callers re-ran the aggregate on every request.
 *
 * 3. **Nothing with a class may cross the boundary.** Every serializing store
 *    unserializes through `cache.serializable_classes`, left at the framework
 *    default of `false` so a leaked APP_KEY cannot become a gadget chain. A
 *    cached query row therefore returns as __PHP_Incomplete_Class and fatals
 *    on first use. This class cannot enforce that on its callers, so each one
 *    reduces its rows to plain arrays before handing them over — and this
 *    note is here so the next caller knows it has to.
 *
 * 4. **Shared slices are built under a lock; per-viewer slices are not.** A
 *    write anywhere retires every cached slice at once, so the moment one
 *    pilot submits, every viewer misses simultaneously. Left alone they would
 *    each answer the miss with the same full aggregate, and the load would
 *    rise with traffic exactly when there is least room for it. Waiting is
 *    bounded and never fatal: whoever times out computes the slice itself,
 *    which is what every request did before. A per-viewer slice can only
 *    collide with that same pilot's own requests, so taking a lock on one
 *    would just be two more cache writes on the miss.
 */
final readonly class SliceCache
{
    /**
     * How long one process may hold the right to rebuild a shared slice, and
     * how long the others will wait for it before giving up and building
     * their own.
     */
    private const int BUILD_LOCK_SECONDS = 30;

    private const int BUILD_WAIT_SECONDS = 5;

    /**
     * @param  string  $prefix  namespaces both the generation counter and every
     *                          slice, so two read models never collide
     * @param  int  $ttlSeconds  backstop for the things that move a slice
     *                           without going through a write
     */
    public function __construct(
        private string $prefix,
        private int $ttlSeconds,
    ) {}

    /**
     * Cache a slice against the current generation.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $compute
     * @param  bool  $shared  whether every viewer reads this same slice, and
     *                        so whether a miss is worth serialising
     * @return TValue
     */
    public function remember(string $key, Closure $compute, bool $shared = true): mixed
    {
        $generation = Cache::get($this->generationKey(), 0);
        $cacheKey = sprintf('%s:%s:%s', $this->prefix, $generation, $key);

        $cached = Cache::get($cacheKey);

        if (is_array($cached) && array_key_exists('value', $cached)) {
            return $cached['value'];
        }

        $build = function () use ($cacheKey, $compute): mixed {
            $value = $compute();
            Cache::put($cacheKey, ['value' => $value], $this->ttlSeconds);

            return $value;
        };

        if (! $shared) {
            return $build();
        }

        try {
            return Cache::lock($cacheKey.':building', self::BUILD_LOCK_SECONDS)
                ->block(self::BUILD_WAIT_SECONDS, function () use ($cacheKey, $build): mixed {
                    // The build we queued behind may have finished while we
                    // waited, in which case there is nothing left to do.
                    $cached = Cache::get($cacheKey);

                    if (is_array($cached) && array_key_exists('value', $cached)) {
                        return $cached['value'];
                    }

                    return $build();
                });
        } catch (LockTimeoutException) {
            return $build();
        }
    }

    /**
     * Retire every cached slice under this prefix.
     *
     * One write. Rather than tracking which entries a given change could have
     * moved, the generation is bumped and every old key simply stops being
     * looked up — the stale entries age out on their own.
     */
    public function flush(): void
    {
        if (Cache::add($this->generationKey(), 1)) {
            return;
        }

        Cache::increment($this->generationKey());
    }

    private function generationKey(): string
    {
        return $this->prefix.':generation';
    }
}
