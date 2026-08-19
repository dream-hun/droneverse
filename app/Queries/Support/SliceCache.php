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
 * each query still chooses its own prefix, its own TTL, which of its slices
 * are worth serialising and — the decision this class cannot make for them —
 * what each slice is actually derived from. What is shared is the mechanism,
 * and every line of it is a defect that was found the hard way, which is the
 * argument against a second copy of it.
 *
 * Five properties it exists to hold:
 *
 * 1. **A slice is keyed against the generations of the scopes it reads.** The
 *    original had exactly one counter per read model, so any write retired
 *    every cached slice: one pilot submitting a run meant every viewer of
 *    every course board missed at once, and the aggregate load rose with
 *    traffic. A scope names a population a slice is derived from — one pilot,
 *    one mission, one course — and a write bumps only the scopes it actually
 *    moved. Slices that could not have changed keep being served.
 *
 * 2. **The generation counter is seeded before it is bumped, atomically.**
 *    Only some cache drivers treat `increment` on an absent key as counting
 *    up from zero; the database and memcached stores return false and write
 *    nothing, so bumping a generation no write had ever created left the
 *    model pinned at generation zero and every invalidation silently did
 *    nothing. `add` is the create-if-absent that closes it, and creating the
 *    counter *is* the first bump, so it never double-counts.
 *
 *    It only closes it with a TTL, though, and that is the part that is easy
 *    to get wrong: `Illuminate\Cache\Repository::add()` delegates to the
 *    store's atomic `add` **only when one is given**, and otherwise falls
 *    back to a `get()` followed by an unconditional `put()`. Called without
 *    one, two writers can both find the counter absent, and the second's
 *    `put` resets a generation the first has already bumped past —
 *    resurrecting every slice built against the old one for the rest of its
 *    TTL. That is a lost invalidation, which is the one failure this whole
 *    class exists to prevent, so the TTL below is load-bearing rather than
 *    housekeeping.
 *
 * 3. **Cached values are wrapped rather than stored bare.** A slice can
 *    legitimately be `null` — a pilot who has not flown has no standing and
 *    no curve — and a bare null is indistinguishable from a miss. Unwrapped,
 *    exactly those callers re-ran the aggregate on every request.
 *
 * 4. **Nothing with a class may cross the boundary.** Every serializing store
 *    unserializes through `cache.serializable_classes`, left at the framework
 *    default of `false` so a leaked APP_KEY cannot become a gadget chain. A
 *    cached query row therefore returns as __PHP_Incomplete_Class and fatals
 *    on first use. This class cannot enforce that on its callers, so each one
 *    reduces its rows to plain arrays before handing them over — and this
 *    note is here so the next caller knows it has to.
 *
 * 5. **Shared slices are built under a lock; per-viewer slices are not.** A
 *    write retires every cached slice in the scopes it touched, so the moment
 *    one pilot submits, every viewer of a slice reading that scope misses
 *    simultaneously. Left alone they would each answer the miss with the same
 *    full aggregate. Waiting is bounded and never fatal: whoever times out
 *    computes the slice itself, which is what every request did before. A
 *    per-viewer slice can only collide with that same pilot's own requests,
 *    so taking a lock on one would just be two more cache writes on the miss.
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
     * How much longer a generation counter lives than the slices it keys.
     *
     * The counter has to outlive every slice built against it: if it expired
     * while one was still cached, the next write would recreate it at
     * generation 1 and start handing out keys that an entry from the last
     * time it stood at 1 could still answer. A multiple this large makes that
     * impossible in practice — the counter is read on every request that
     * touches the model, so it is the hottest key either read model has, and
     * it is only ever absent before the first write to a brand new scope.
     *
     * A TTL at all, rather than `forever`, because `forever` is what costs
     * the atomicity described above.
     */
    private const int GENERATION_TTL_MULTIPLE = 1000;

    /**
     * @param  string  $prefix  namespaces both the generation counters and
     *                          every slice, so two read models never collide
     * @param  int  $ttlSeconds  backstop for the things that move a slice
     *                           without going through a write
     */
    public function __construct(
        private string $prefix,
        private int $ttlSeconds,
    ) {}

    /**
     * Cache a slice against the current generation of every scope it reads.
     *
     * The scopes are the caller's statement about what the slice is derived
     * from, and they are what makes invalidation cheap: a slice listing a
     * pilot and a mission survives every write that touched neither. Listing
     * too few is a correctness bug — the slice will go on being served after
     * a write that moved it — so a slice that genuinely depends on everything
     * should say so with one scope that every write bumps.
     *
     * @template TValue
     *
     * @param  list<string>  $scopes  populations this slice is derived from
     * @param  Closure(): TValue  $compute
     * @param  bool  $shared  whether every viewer reads this same slice, and
     *                        so whether a miss is worth serialising
     * @return TValue
     */
    public function remember(string $key, array $scopes, Closure $compute, bool $shared = true): mixed
    {
        $cacheKey = $this->cacheKey($key, $scopes);

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
     * Retire every cached slice that reads one of the given scopes.
     *
     * One write per scope. Rather than tracking which entries a given change
     * could have moved, the scope's generation is bumped and every key built
     * against the old one simply stops being looked up — the stale entries
     * age out on their own.
     *
     * Passing no scopes is a no-op rather than a full flush. A caller that
     * worked out nothing moved should not accidentally retire everything, and
     * a caller that wants everything gone has a scope for it.
     */
    public function flush(string ...$scopes): void
    {
        foreach (array_unique($scopes) as $scope) {
            $key = $this->generationKey($scope);

            if (Cache::add($key, 1, $this->generationTtlSeconds())) {
                continue;
            }

            Cache::increment($key);
        }
    }

    /**
     * The key a slice lives under, given where its scopes currently stand.
     *
     * Every scope's generation is read in one round trip rather than one
     * lookup each: a multi-scope slice is read on every page that shows it,
     * and the whole point of the split is that reads stay cheap.
     *
     * Scopes are sorted so that a caller naming the same two populations in
     * either order lands on the same entry instead of quietly keeping two
     * copies of it.
     *
     * @param  list<string>  $scopes
     */
    private function cacheKey(string $key, array $scopes): string
    {
        $scopes = array_values(array_unique($scopes));
        sort($scopes);

        $generationKeys = array_map($this->generationKey(...), $scopes);
        $generations = Cache::many($generationKeys);

        $stamp = implode('|', array_map(
            fn (string $scope, string $generationKey): string => sprintf(
                '%s=%d',
                $scope,
                (int) ($generations[$generationKey] ?? 0),
            ),
            $scopes,
            $generationKeys,
        ));

        return sprintf('%s:%s:%s', $this->prefix, $stamp, $key);
    }

    private function generationKey(string $scope): string
    {
        return sprintf('%s:generation:%s', $this->prefix, $scope);
    }

    /**
     * How long a generation counter is kept, derived from the slice TTL it
     * guards so that a caller choosing a longer-lived slice cannot leave its
     * counter expiring underneath it.
     */
    private function generationTtlSeconds(): int
    {
        return $this->ttlSeconds * self::GENERATION_TTL_MULTIPLE;
    }
}
