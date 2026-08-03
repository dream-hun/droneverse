<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * What the browser needs to know about Lemon Squeezy before it opens checkout.
 *
 * Which is almost nothing, and that is the point. Lemon.js takes no publishable
 * key and no environment flag — unlike Paddle.js, which had to be handed a
 * client-side token and told whether it was in sandbox. Every value that
 * identifies this seller stays on the server, and the checkout the browser
 * opens is a URL the server already minted.
 *
 * So the one thing the pages need is whether checkout can open at all, and this
 * exists to answer that in a form the pricing page can read.
 */
final readonly class BuildLemonSqueezyClientConfig
{
    /**
     * `configured` is false whenever this environment cannot mint a checkout —
     * the normal state locally and in tests, where neither key is set. Both
     * values are needed: the store names who is selling and the API key is what
     * authorises the call that creates the checkout, and either one missing
     * makes StartCheckout throw rather than return a URL.
     *
     * The pages read this as "the upgrade buttons stay disabled". It is
     * presentation only — ResolveCheckoutPrice is the guard that actually
     * refuses to sell, and it runs whatever the browser believes.
     *
     * @return array{configured: bool}
     */
    public function handle(): array
    {
        $apiKey = config('lemon-squeezy.api_key');
        $store = config('lemon-squeezy.store');

        return [
            'configured' => is_string($apiKey) && $apiKey !== ''
                && is_string($store) && $store !== '',
        ];
    }
}
