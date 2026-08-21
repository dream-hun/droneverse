<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\HandleCreemWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Where Creem posts, and the only endpoint here that grants a paid plan without
 * a session behind it.
 *
 * It is authenticated by App\Http\Middleware\VerifyCreemWebhookSignature and by
 * nothing else, so this method may assume the body is Creem's — and must not
 * assume anything else about it, which is why every field is read defensively
 * on the way down.
 *
 * Always 200, short of an exception. Creem redelivers anything that is not a
 * 2xx, five times over six hours, so the response is a statement about whether
 * the delivery was received rather than about whether it changed anything: an
 * event this application does not handle, and one naming an account it cannot
 * place, are both received.
 */
final class CreemWebhookController extends Controller
{
    public function __invoke(Request $request, HandleCreemWebhook $webhook): Response
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        $webhook->handle($payload);

        return response()->noContent(Response::HTTP_OK);
    }
}
