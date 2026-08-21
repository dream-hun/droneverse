<?php

declare(strict_types=1);

namespace App\Actions;

use App\Http\Integrations\Creem;

/**
 * What the browser needs to know about Creem before it opens checkout.
 *
 * Which is almost nothing, and that is the point. Creem's embed script takes no
 * publishable key and no environment flag — unlike Paddle.js, which had to be
 * handed a client-side token and told whether it was in sandbox. Every value
 * that identifies this seller stays on the server, and the checkout the browser
 * opens is a URL the server already minted, with the product and the price
 * baked into it before it left.
 *
 * So the one thing the pages need is whether checkout can open at all, and this
 * exists to answer that in a form the pricing page can read.
 */
final readonly class BuildCreemClientConfig
{
    public function __construct(private Creem $creem)
    {
        //
    }

    /**
     * `configured` is false whenever this environment cannot mint a checkout —
     * the normal state locally and in tests, where no API key is set. The key
     * is the whole of it: Creem needs no store identifier, and the key's own
     * prefix decides whether test or live products are being sold.
     *
     * The pages read this as "the upgrade buttons stay disabled". It is
     * presentation only — ResolveCheckoutPrice is the guard that actually
     * refuses to sell, and it runs whatever the browser believes.
     *
     * @return array{configured: bool}
     */
    public function handle(): array
    {
        return ['configured' => $this->creem->configured()];
    }
}
