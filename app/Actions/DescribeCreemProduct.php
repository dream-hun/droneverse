<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Plan;

/**
 * Name the thing a Creem product ID sells, for a person reading a ledger.
 *
 * "Pro · yearly" rather than `prod_7hG2…`. The mapping is config/plans.php
 * read in reverse, which is the same reading entitlement uses, so a product
 * this names is one a subscriber to it is actually granted. One it cannot
 * name — retired, mistyped, sold from a different store — is shown as the raw
 * ID rather than guessed at: a ledger that invents a plan is worse than one
 * that admits it does not recognise a product.
 */
final readonly class DescribeCreemProduct
{
    public function handle(string $productId): string
    {
        $plan = Plan::fromPriceId($productId);

        if (! $plan instanceof Plan) {
            return $productId;
        }

        $variant = $plan->variantFor($productId);

        return $variant === null
            ? $plan->label()
            : sprintf('%s · %s', $plan->label(), str_replace('_', ' ', $variant));
    }
}
