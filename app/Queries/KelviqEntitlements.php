<?php

declare(strict_types=1);

namespace App\Queries;

use App\Http\Integrations\Kelviq;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Which Kelviq features a pilot holds, as Kelviq answers it.
 *
 * The port of the SDK's `entitlements.hasAccess()`, including its cache, and
 * the one place in the application that asks Kelviq what anybody has paid
 * for. App\Actions\ResolvePlanForUser and App\Actions\ResolveFeaturesForUser
 * read it; nothing else should.
 *
 * The customer is the pilot's `uuid`, the identifier the application already
 * uses for them in public. It is always read from the signed-in user on the
 * server and never from anything a request carries.
 *
 * Reads follow the SDK's order:
 *
 *   1. A fresh answer from the cache, for `kelviq.cache_ttl` seconds.
 *   2. Kelviq's edge API, whose answer refreshes the cache.
 *   3. When Kelviq cannot be reached or is failing, the last good answer, kept
 *      for `kelviq.stale_ttl` seconds.
 *   4. Nothing at all. An entitlement check that errors with no last good
 *      answer to fall back on fails closed: the pilot is on Starter until
 *      Kelviq answers, which is an outage for a subscriber and never free Pro
 *      for anybody. The fallback is itself cached for the fresh TTL, so an
 *      outage costs one failed call a minute per pilot rather than one per
 *      page.
 *
 * A customer Kelviq has never heard of is answered like one with no
 * subscription: no features, cached like any other answer.
 *
 * Only feature identifiers are cached, never a response object, so nothing
 * with a class crosses the cache boundary.
 */
final readonly class KelviqEntitlements
{
    public function __construct(private Kelviq $kelviq)
    {
        //
    }

    public function hasAccess(User $user, string $featureId): bool
    {
        return in_array($featureId, $this->for($user), true);
    }

    /**
     * The identifiers of every feature the pilot has access to.
     *
     * @return array<int, string>
     */
    public function for(User $user): array
    {
        if (! $this->kelviq->configured()) {
            return [];
        }

        $customerId = $user->uuid;
        $fresh = Cache::get($this->key($customerId));

        if (is_array($fresh)) {
            return $this->identifiers($fresh);
        }

        try {
            $granted = $this->granted($customerId, $this->kelviq->entitlements($customerId));
        } catch (RequestException $exception) {
            $granted = $exception->response->notFound() ? [] : $this->fallback($customerId, $exception);
        } catch (Throwable $exception) {
            $granted = $this->fallback($customerId, $exception);
        }

        Cache::put($this->key($customerId), $granted, $this->ttl('kelviq.cache_ttl', 60));

        return $granted;
    }

    /**
     * Drop the cached answer so the next read asks Kelviq.
     *
     * For the moments the answer is known to have just changed: a buyer landing
     * back from checkout, and a webhook about the customer. The last good
     * answer is kept, because it is only ever read when Kelviq cannot be.
     */
    public function forget(string $customerId): void
    {
        Cache::forget($this->key($customerId));
    }

    /**
     * The SDK's aggregation, for the feature types this catalog uses.
     *
     * A feature can arrive once per subscription, and it is granted if any of
     * those entries grants it. The catalog has no METER features, so the
     * SDK's usage arithmetic has nothing to act on here and is not ported.
     *
     * The answer is also kept as the last good one, for fallback() to serve.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, string>
     */
    private function granted(string $customerId, array $entries): array
    {
        $granted = [];

        foreach ($entries as $entry) {
            $featureId = $entry['featureId'] ?? null;

            if (is_string($featureId) && ($entry['hasAccess'] ?? false) === true) {
                $granted[$featureId] = $featureId;
            }
        }

        $granted = array_values($granted);

        Cache::put($this->staleKey($customerId), $granted, $this->ttl('kelviq.stale_ttl', 86_400));

        return $granted;
    }

    /**
     * @return array<int, string>
     */
    private function fallback(string $customerId, Throwable $exception): array
    {
        $stale = Cache::get($this->staleKey($customerId));

        Log::warning('Kelviq entitlements could not be read; '.(is_array($stale) ? 'serving the last good answer.' : 'failing closed.'), [
            'customer_id' => $customerId,
            'exception' => $exception->getMessage(),
        ]);

        return is_array($stale) ? $this->identifiers($stale) : [];
    }

    /**
     * @param  array<mixed>  $cached
     * @return array<int, string>
     */
    private function identifiers(array $cached): array
    {
        return array_values(array_filter($cached, is_string(...)));
    }

    private function ttl(string $key, int $default): int
    {
        $ttl = config($key);

        return is_int($ttl) && $ttl > 0 ? $ttl : $default;
    }

    private function key(string $customerId): string
    {
        return 'kelviq:entitlements:'.$customerId;
    }

    private function staleKey(string $customerId): string
    {
        return 'kelviq:entitlements:'.$customerId.':stale';
    }
}
