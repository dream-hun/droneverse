<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * What the browser needs to boot Paddle.js.
 *
 * Cashier ships a `@paddleJS` Blade directive that does this, but it would put
 * a third-party script on every page of an Inertia app to serve the two that
 * open an overlay. This hands the same values to the pages that actually need
 * them, and `usePaddle()` loads the script there.
 *
 * The client-side token is public by design — it identifies the seller to
 * Paddle and authorises nothing. The API key, which does, never leaves the
 * server.
 */
final readonly class BuildPaddleClientConfig
{
    /**
     * `token` is null when Paddle is unconfigured, which is the normal state
     * locally and in tests. The pages read that as "checkout is not available"
     * rather than loading a script that cannot initialise.
     *
     * @return array{token: string|null, sandbox: bool}
     */
    public function handle(): array
    {
        $token = config('cashier.client_side_token');

        return [
            'token' => is_string($token) && $token !== '' ? $token : null,
            'sandbox' => (bool) config('cashier.sandbox'),
        ];
    }
}
