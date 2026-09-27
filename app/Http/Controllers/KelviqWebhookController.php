<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\HandleKelviqWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Where Kelviq posts.
 *
 * Authenticated by App\Http\Middleware\VerifyKelviqWebhookSignature and by
 * nothing else, so this may assume the event is Kelviq's — and must not assume
 * anything else about it, which is why every field is read defensively.
 *
 * Always 200, short of an exception. Kelviq redelivers anything that is not a
 * 2xx, so the response says the delivery was received rather than that it
 * changed anything.
 */
final class KelviqWebhookController extends Controller
{
    /** How long an event id is remembered as already handled. */
    private const int DEDUPE_DAYS = 7;

    /**
     * @throws Throwable
     */
    public function __invoke(Request $request, HandleKelviqWebhook $webhook): Response
    {
        /** @var array<string, mixed> $event */
        $event = $request->attributes->get('kelviq_event', []);

        $id = $event['id'] ?? null;

        /*
         * Retries and manual resends deliver the same event again, under the
         * same `id`. `add` claims it atomically — it writes only when the key
         * is absent — so two copies arriving together cannot both be handled.
         * The claim is released if handling throws, so Kelviq's retry is not
         * mistaken for a duplicate of a delivery that never finished.
         */
        if (! is_string($id) || $id === '' || ! Cache::add($this->key($id), true, now()->addDays(self::DEDUPE_DAYS))) {
            return response()->noContent(Response::HTTP_OK);
        }

        try {
            $webhook->handle($event);
        } catch (Throwable $throwable) {
            Cache::forget($this->key($id));

            throw $throwable;
        }

        return response()->noContent(Response::HTTP_OK);
    }

    private function key(string $id): string
    {
        return 'kelviq:webhook:'.$id;
    }
}
