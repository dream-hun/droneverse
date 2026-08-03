# Stripe Managed Payments Research

Whether DroneVerse should bill through Stripe Managed Payments instead of
Paddle, what it actually costs, and why the recommendation is to not do it.

This is the companion to
[lemonsqueezy-integration-research.md](lemonsqueezy-integration-research.md),
written to answer the open question that document raised and deliberately left
unanswered. For how billing works today, see [architecture.md](architecture.md)
§3.4 and §5.

**Contents**

1. [Executive summary](#1-executive-summary)
2. [A correction to the Lemon Squeezy document](#2-a-correction-to-the-lemon-squeezy-document)
3. [What Managed Payments is](#3-what-managed-payments-is)
4. [What it costs, stacked](#4-what-it-costs-stacked)
5. [Architectural fit with this codebase](#5-architectural-fit-with-this-codebase)
6. [All three providers, compared](#6-all-three-providers-compared)
7. [Risks and open questions](#7-risks-and-open-questions)

---

## 1. Executive summary

**Recommendation: do not migrate. Stay on Paddle. There is no third option
worth researching after this one.**

The Lemon Squeezy document closed by pointing here, on the belief that Managed
Payments charged the same 5% + $0.50 and would therefore be a strictly better
version of the same trade. That belief was wrong, and correcting it removes the
entire reason to look.

Managed Payments is **a 3.5% add-on levied on top of standard Stripe processing
fees**, not an all-in rate. For the product DroneVerse actually sells — a
subscription, bought from anywhere in the world, priced at $19/mo — the stacked
cost is roughly **$2.12 per transaction against Paddle's $1.45**, about 46%
more. It is the most expensive of the three options evaluated, not the cheapest.

The architectural fit is worse than Lemon Squeezy's, which is the more
surprising finding. Managed Payments does not support subscriptions created
through Stripe's API, only those created through Checkout or Payment Links, and
it does not support invoice items or one-off invoices on a subscription. That
second restriction is the mechanism proration uses when a seat count changes —
so **Phase 7 Team seats is constrained here too**, by a different route than
Lemon Squeezy blocked it. The expectation that Stripe Billing's mature quantity
handling would rescue the seat model does not survive contact with the
Managed Payments carve-outs.

What remains true: `cashier-stripe` is first-party Laravel, maintained at the
framework's cadence, and materially better maintained than
`lemonsqueezy/laravel`. That is a real advantage and it is not enough. It buys a
better-supported package in exchange for a higher fee, a narrower feature set,
and a rebuild of the checkout path — while forcing every existing subscriber to
re-enter their card, because both Paddle and Stripe are merchants of record and
neither can transfer a payment method to the other.

**The question to redirect to is not another provider.** If the motivation is
friction with Paddle — approval delays, the domain allowlist, support latency —
that is an account-management problem and it is cheaper to solve with Paddle's
support than with any migration described in these two documents.

---

## 2. A correction to the Lemon Squeezy document

[lemonsqueezy-integration-research.md](lemonsqueezy-integration-research.md)
states in §1, §3.1 and §7 that Managed Payments is priced "at the *same* headline
rate Lemon Squeezy charges — 5% + $0.50". **This is incorrect.** Stripe's own
pricing documentation describes a 3.5% fee "in addition to the standard Stripe
payment processing fees".

The error mattered, because it was load-bearing for that document's closing
recommendation to research Managed Payments instead. Those passages have been
annotated in place. The conclusion of that document — stay on Paddle, do not
adopt Lemon Squeezy — is unaffected; if anything the correction strengthens it,
since Lemon Squeezy is now the *middle* option on cost rather than tied for
cheapest.

The reason the error is easy to make: Stripe markets the 3.5% as the Managed
Payments price, and it is, in the sense that it is the fee for the
merchant-of-record service specifically. It is quoted separately from processing
because Stripe lets you apply it selectively. Paddle's 5% + $0.50 is quoted the
other way round — one number covering everything, including the cross-border and
currency-conversion surcharges that Stripe itemises.

---

## 3. What Managed Payments is

Stripe's merchant-of-record product, announced February 2026 and generally
available since April 2026. Stripe becomes the seller of record and takes on
indirect tax registration and remittance in 80+ countries, fraud prevention,
dispute management, and transaction-level buyer support.

It is the strategic destination for Lemon Squeezy, which Stripe acquired in
July 2024 and is building migration paths out of. That much of the Lemon Squeezy
document's reasoning holds.

**Supported surfaces.** Stripe Checkout, Payment Links, native mobile, and
Stripe Billing for the recurring layer.

**Explicitly not supported:**

- Subscriptions created through the Stripe API as part of a custom billing flow
- Attaching invoice items to a subscription
- Generating one-off invoices outside the billing period
- Stripe Connect, in any form — no marketplaces or platform accounts
- Elements and other embeddable components; any custom payment UI falls outside
  Managed Payments coverage
- Third-party tax integrations

**Eligibility.** Digital products — SaaS, software, downloadable content, which
DroneVerse qualifies as. Primarily US-based sellers in good standing; businesses
based in India, Brazil, South Korea and Turkey are excluded. Buyer-side
restrictions apply to China, Russia, Iran, Cuba, North Korea, Syria and Kosovo.

**Not included, that a billing stack still needs:** license key management,
dunning and retry logic on failed subscription payments, abandoned-cart
recovery, and a customer billing portal. Paddle supplies the retry and portal
pieces today.

---

## 4. What it costs, stacked

For one $19.00/mo subscription payment. Stripe's components are itemised;
Paddle's single rate is shown for comparison.

| Component | Domestic US | International + FX |
| --- | ---: | ---: |
| Standard processing — 2.9% + 30¢ | $0.85 | $0.85 |
| International card — +1.5% | — | $0.29 |
| Currency conversion — +1% | — | $0.19 |
| Managed Payments — +3.5% | $0.67 | $0.67 |
| Stripe Billing — +0.7% pay-as-you-go | $0.13 | $0.13 |
| **Stripe total** | **$1.65** | **$2.12** |
| | *8.7%* | *11.2%* |
| **Paddle, all-in — 5% + 50¢** | **$1.45** | **$1.45** |
| | *7.6%* | *7.6%* |

Two things this table makes visible.

**The geography sensitivity is the real story.** Paddle's rate does not move
when the buyer is abroad or paying in another currency. Stripe's moves by 29%.
DroneVerse is a browser-delivered flight simulator with no geographic anchor —
the international column is the common case, not the edge case.

**Stripe Billing is a separate product with its own fee.** Managed Payments
covers the merchant-of-record service; recurring billing is layered on top at
0.7% of billing volume on the pay-as-you-go plan, or from $620/mo on contract.
At DroneVerse's volume, pay-as-you-go is obviously correct, and it is the
0.7% row above.

The $620/mo contract tier becomes cheaper than 0.7% only past roughly $88,000
of monthly billing volume — far beyond anything these documents should be
planning around.

---

## 5. Architectural fit with this codebase

### 5.1 The subscription-creation restriction

`cashier-stripe`'s primary path is `newSubscription()->create()`, which builds
the subscription through Stripe's API. **Managed Payments does not support
API-created subscriptions.** Cashier would be usable only through its
`->checkout()` mode, where Stripe hosts the page and Cashier reconciles after
the fact via webhook.

That is not fatal on its own — it is roughly the shape the Paddle overlay
already has — but it means the migration is a rewrite of the checkout path
rather than a swap of its backing library, and it permanently removes the
option of a custom in-app payment form.

### 5.2 Phase 7 seats, blocked again

`config/plans.php:46-56` sells Team as a base subscription plus quantity-priced
seats at $5/mo past the ten the base covers. Changing a seat count mid-period
requires proration, and proration is expressed in Stripe as an invoice item
attached to the subscription.

**Managed Payments supports neither invoice items on a subscription nor one-off
invoices outside the billing period.** So the seat model is constrained here by
the same practical outcome that blocked it on Lemon Squeezy, arrived at
differently: Lemon Squeezy has no `subscription_items` at all, while Stripe has
them but Managed Payments carves out the proration mechanism that makes changing
them meaningful.

This was the assumption most worth testing, and it failed. Stripe Billing's
quantity handling is genuinely good; Managed Payments does not give you the part
of it this product needs.

### 5.3 What would survive the move

| Concern | Today | Under Managed Payments |
| --- | --- | --- |
| Webhook signature verification | `PaddleWebhookController.php` — fail-closed patch over Cashier's fail-open default | Same patch required again; `cashier-stripe` has the same conditional-middleware shape |
| Plan resolution | `ResolvePlanForUser::fromSubscription()` (`:70`) reads `subscription_items` | Survives — Cashier keeps `subscription_items` |
| Next payment amount | `BuildBillingSummary::nextPayment()` (`:115-131`) | Survives — Stripe exposes upcoming invoice amounts |
| Money formatting | `Cashier::formatAmount()` at `BuildPricingCatalog.php:84` | Survives unchanged — same Cashier helper, same `moneyphp/money` |
| Client config | `BuildPaddleClientConfig.php` (37 lines) | Disappears — checkout becomes a server-generated session |
| Checkout error surface | `use-paddle.ts:22-78` maps `E-403`, `transaction_default_checkout_url_not_set` | Rebuilt against Stripe's redirect-and-return model; no overlay error events to map |
| Customer portal | Paddle-hosted | Not provided by Managed Payments; would need building or a separate Stripe product |

The `nextPayment` and money-formatting rows are the two places Managed Payments
is genuinely better than Lemon Squeezy, which could support neither cleanly.

### 5.4 The webhook patch, a third time

Worth stating plainly because it has now come up for all three providers:
`cashier-stripe` guards its signature-verification middleware on the presence of
a configured secret, exactly as `cashier-paddle` and `lemonsqueezy/laravel` do.
`PaddleWebhookController.php` overrides this so that a missing secret rejects
every call rather than accepting every call. Any provider migration re-does that
override and re-ports its test at `PaddleWebhookTest.php`.

---

## 6. All three providers, compared

For a $19/mo subscription sold internationally, which is this product's
typical transaction.

| | Paddle *(today)* | Lemon Squeezy | Stripe Managed Payments |
| --- | --- | --- | --- |
| Cost per transaction | **$1.45** | $1.83 | $2.12 |
| Effective rate | **7.6%** | 9.6% | 11.2% |
| Rate varies by geography | No | Yes | Yes |
| Laravel package | `cashier-paddle`, first-party | `lemonsqueezy/laravel`, community, 1 release/yr | `cashier-stripe`, **first-party, best maintained** |
| Quantity/seat billing | **Yes — `subscription_items`** | No — one variant per subscription | Present in Billing, carved out by Managed Payments |
| Custom checkout UI | Overlay | Overlay | Hosted only |
| Customer portal | **Provided** | Provided | Not provided |
| Dunning / retries | **Provided** | Provided | Not provided |
| Strategic risk | Independent vendor | **Acquired; migration paths being built out of it** | Strategic product |
| Migration cost from today | **None** | Every subscriber re-enters card | Every subscriber re-enters card |

Paddle wins every row that this product depends on except package maintenance
and strategic durability. Managed Payments wins the package row decisively and
loses on cost, seat billing, portal, dunning and checkout flexibility.

---

## 7. Risks and open questions

**The strategic risk runs the other way than expected.** The Lemon Squeezy
document treated vendor durability as the deciding factor, which was right for
that comparison. Applied here it argues *for* Managed Payments — Stripe is not
going to be acquired. But durability only matters if the destination is
otherwise viable, and on cost and seat billing it is not. Paying 46% more per
transaction to hedge against Paddle being acquired is buying insurance more
expensive than the risk.

**Paddle carries a real concentration risk that neither document resolves.**
Paddle is a private company and a single point of failure for all revenue. The
mitigation is not a second merchant of record; it is keeping the entitlement
layer provider-agnostic. `ResolvePlanForUser` already does most of this by
resolving a `Plan` rather than reading Paddle state throughout the app. That
property is worth protecting in review regardless of what happens here.

**Managed Payments eligibility is unconfirmed for this entity.** The US-primary
restriction is documented but the operating entity's jurisdiction has not been
checked against it. Irrelevant given the recommendation; it would be the first
gate if that recommendation were ever revisited.

### Open questions

1. **What is the actual friction with Paddle?** Both documents now end here.
   Nothing in either justifies a migration on technical grounds, so the
   motivation is presumably operational. Name it, and the fix is probably a
   support conversation.
2. **Is Team-with-seats (Phase 7) settled?** It is now the constraint that has
   eliminated two providers. If banded tiers would serve the product as well,
   the provider landscape widens considerably — and that is a pricing decision,
   not an engineering one.
3. **Should the entitlement layer be hardened against provider change?**
   Not urgent, but the cheapest possible insurance against every scenario in
   these two documents, and it is mostly already true.

---

## Sources

- [Managed Payments pricing](https://support.stripe.com/questions/managed-payments-pricing?locale=en-GB) — the 3.5% add-on, stated as additional to processing fees
- [Managed Payments documentation](https://docs.stripe.com/payments/managed-payments) — supported surfaces, subscription and invoice-item restrictions, eligibility
- [Stripe pricing](https://stripe.com/pricing) — 2.9% + 30¢, +1.5% international, +1% conversion, Billing at 0.7% or $620/mo
- [Paddle pricing](https://www.paddle.com/pricing) — 5% + 50¢ all-inclusive, no separate international or conversion fees
- [Freemius: where Stripe Managed Payments fails](https://freemius.com/blog/stripe-merchant-of-record/) — API-created subscriptions unsupported, geographic exclusions, missing portal and dunning
- [Laravel Cashier (Stripe)](https://laravel.com/docs/13.x/billing) — package capabilities and Stripe API version pinning
