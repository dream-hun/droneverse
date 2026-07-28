# Pricing Implementation Plan

> How DroneVerse gets from today's fully-free platform to the tiers described in
> [`pricing.md`](./pricing.md).

---

## Where we stand

| | Today |
|---|---|
| Catalog | 5 courses, 20 missions — gated: 5 missions free, 15 behind Pro |
| Runtime | JavaScript only; grading runs server-side in `app/Actions/GradeSimulatorRun.php` |
| Auth | Fortify, 2FA, passkeys |
| Billing | `laravel/cashier-paddle` installed and wired end to end: config, migrations, `Billable`, a verified webhook endpoint, a public pricing page, server-side checkout and a billing settings page. Still needs real sandbox credentials — every price ID in `config/plans.php` is unset, so no checkout can open |
| Entitlements | Phases 1–3 shipped — `Plan`, `Feature`, `ResolvePlanForUser`, `HasPlan`, a Gate per feature, entitlements shared to React, the catalog gated against them, and `/pricing` selling Pro. **Nothing has been sold yet**: the machinery is complete and the Paddle catalogue is empty |

Of the features the comparison table sells, only Courses, Simulations, JavaScript
and Basic Analytics ship today. Python, Mission Builder, drone config editor,
certificates, team management, classroom tools, API and SSO are all unbuilt.

**The critical path is therefore not "add a pricing page."** It is: build an
entitlement layer, gate the catalog against it, wire Paddle, and only then sell
tiers whose features exist. Phases 0–5 are monetization infrastructure and can
ship in weeks. Phases 6–8 are product build-out and are measured in months.

---

## Decisions — all settled

1. **What does Starter's "3 courses / 5 missions" mean against a 5-course,
   20-mission catalog?** ✅ Marked as Starter content via a `required_plan`
   column — 3 browsable beginner courses, Drone Basics flyable — and
   `pricing.md` reworded to "3 beginner courses (5 missions)" so the copy
   matches the data.
2. **Existing users lose access the day gating lands.** ✅ A nullable
   `plan_override` column on `users`, backfilled to `pro` for every pre-launch
   account. It doubles as the comp / staff / academic-discount mechanism
   `pricing.md` promises under "student discount".
3. **"First 100 Users" — first 100 signups, or first 100 paying customers?**
   ✅ **Paying customers.** A signup-based count leaks the discount to accounts
   that never convert and is bounded by nothing you control. Recorded in
   `pricing.md` and in the `config/plans.php` comment above the `_launch`
   variants; Phase 5 counts against it.
4. **Lifetime's $499 list price was never reachable** ($299 for 1–100, $399 for
   101–500, sold out past 500). ✅ **Resolved by removing the Lifetime plan
   entirely**, not by fixing the ladder. A one-off sale honoured forever prices
   a bet on costs that cannot be seen yet, and it is the only sale that cannot
   be repriced afterwards. A solo developer carrying that liability against a
   platform still being built is the wrong risk to take for the cash. Phase 4
   is gone with it, and so are `lifetime_purchased_at`, the seat ladder and the
   founder-badge work it implied.
5. **"Future premium AI credits beyond the included allowance" was undefined.**
   ✅ **Resolved by dropping the AI coding assistant from the product.** Every
   remaining capability is a fixed cost to build and free to serve; the
   assistant was the only one carrying a per-call marginal cost, which is a
   metering problem — credit ledger, allowance, hard stop, top-up path — before
   it is a feature. `Feature` carries a note saying that adding the case back
   means building the ledger with it.

---

## Phase 0 — Wire Paddle — ✅ done

Purely mechanical; no product surface changes.

- ✅ `laravel/cashier-paddle` replaces `lemonsqueezy/laravel` in `composer.json`;
  the abandoned Lemon Squeezy tables are dropped in
  `2026_07_27_233410_drop_lemon_squeezy_tables`.
- ✅ `vendor:publish --tag=cashier-migrations` → `customers`, `subscriptions`,
  `subscription_items`, `transactions`, rewritten to project convention
  (`declare(strict_types=1)`, no `down()`), migrated.
- ✅ `config/cashier.php` published. Paddle keys and every price ID added to
  `.env.example`.
- ✅ `Laravel\Paddle\Billable` on `App\Models\User`, with `@property-read`
  annotations for `customer`, `subscriptions` and `transactions`.
- ✅ `config/plans.php` — the single map from plan + variant to Paddle price ID,
  covering the regular, launch and per-seat variants. Env config, not rows.
- ✅ The webhook route is registered as `cashier.webhook` at `POST
  /paddle/webhook` and carries no middleware group at all — Cashier registers it
  from its service provider, outside `withRouting(web:)`, so CSRF never applies.
  Nothing needed changing; the point was to prove it rather than assume it.
- ✅ **Test:** `tests/Feature/PaddleWebhookTest.php` (6) — route registration,
  the absence of CSRF, rejection of an unsigned payload, of one signed with the
  wrong secret, and of a correctly signed but hour-old replay, then a signed
  `subscription.created` that lands a subscription row and flips the pilot to
  Pro on the next request.

**The one thing that was not safe, and now is:** Cashier applies
`VerifyWebhookSignature` only *if* `cashier.webhook_secret` is set, so an
environment that forgot `PADDLE_WEBHOOK_SECRET` did not fail closed — it
accepted any POST to `/paddle/webhook`, and anyone who found it could grant
themselves Pro. `App\Http\Controllers\PaddleWebhookController` now applies the
middleware unconditionally and `AppServiceProvider` binds it in Cashier's place,
so a missing secret rejects every call instead of accepting every call. A
webhook nobody can call is an outage; a webhook anybody can call is a breach.

The remaining work is not code: create the products and prices in the Paddle
sandbox, fill in the `PADDLE_PRICE_*` variables, and point a sandbox webhook at
the endpoint. Until then every plan's CTA correctly reports itself as not for
sale.

**Paddle acts as Merchant of Record**, so VAT/sales tax and invoicing are
handled for us — worth confirming that assumption holds before quoting prices.

---

## Phase 1 — The entitlement layer — ✅ done

The load-bearing phase. Everything after this keys off it.

- ✅ `app/Enums/Plan.php` — `Starter`, `Pro`, `Team`, `Enterprise`, with
  `label()`, `features()`, `hasFeature()`, `isPaid()`, `priceId()`,
  `priceIds()` and `Plan::fromPriceId()` against `config/plans.php`.
- ✅ `app/Enums/Feature.php` — the twelve capability flags.
- ✅ `app/Actions/ResolvePlanForUser.php` — resolution order: `plan_override` →
  active Paddle subscription price ID → `Plan::Starter`. Guests resolve to
  Starter rather than null.
- ✅ `app/Concerns/HasPlan.php` on `User`: `plan()`, `hasFeature()`,
  `features()`, `onPaidPlan()`, `planCovers()`, `forgetPlan()`.
- ✅ A `Gate` per `Feature`, registered in `AppServiceProvider` from the enum
  cases, so `$user->can('python_runtime')` and
  `->middleware('can:python_runtime')` both work with no bespoke wiring.
- ✅ `HandleInertiaRequests` shares `auth.plan` and `auth.features`; the
  `usePlan()` hook and `PlanValue` / `FeatureValue` types read them.
- ✅ **Test:** `tests/Unit/PlanTest.php` (13) and
  `tests/Feature/EntitlementTest.php` (20) — a case per resolution branch, gate
  registration, Inertia sharing, and a gated route that 403s for Starter and
  passes for Pro.

Three decisions were resolved while building:

- **`covers()` is separate from `features()`.** Catalogue depth and capability
  are different axes. Team reaches everything Pro reaches *and* grants an
  instructor dashboard; a tier could do the first without the second, and
  collapsing the two would make that inexpressible.
- **An unrecognised price ID resolves to null, not to a default plan.** A price
  ID we cannot account for is a configuration error; granting Pro for it would
  be worse than granting nothing.
- **`Plan::isSelfServe()` encodes the "don't sell what doesn't exist" rule**, so
  Phase 3 cannot open checkout on Team or Enterprise by accident.

One column was pulled forward from Phase 2, because resolution cannot be
written or tested without it: `plan_override` on `users`
(`2026_07_28_090305_add_plan_override_to_users_table`), plus the `onPlan()`
state on `UserFactory`. `plan_override` is hidden from serialisation. **The
backfill of pre-launch accounts to `pro` remains Phase 2's job** — it belongs in
the same change that turns gating on.

---

## Phase 2 — Gate the catalog — ✅ done

- Migration: `required_plan` (string, default `starter`) on `courses` **and**
  `challenges`; a challenge inherits its course's value unless it sets its own.
- ✅ `required_plan` on `courses` (default `starter`) and on `challenges`
  (**nullable**, where null means "inherit the course"). The plan originally
  called for a defaulted string on both, but a defaulted column can never
  express inheritance — every challenge would silently state a tier of its own
  and drift from its course. Null is the common case; a stored value is the
  deliberate exception.
- ✅ Pre-launch accounts backfilled to `pro`
  (`2026_07_28_092306_backfill_pre_launch_users_to_pro`), in the same
  deployment as the `required_plan` columns. Any gap between the two is a
  window where existing users are locked out of content they already had.
  Accounts that already carry an override are left alone.
- ✅ Locked content stays visible. The `catalog()` scope was **not** given the
  viewer's plan: it filters nothing, because nothing is hidden, so passing the
  plan into the query would have been ceremony. Lock state is resolved in
  `CourseCardResource` and `ChallengeSummaryResource` instead, which is where
  it is actually rendered.
- ✅ `ChallengeController::show` and `::store` both `abort_unless` on
  `Challenge::isUnlockedFor()`, and so does `DronePhotoController::store` —
  the other write path into a mission, which the original plan missed. A
  locked mission that still accepts photos is not locked.
- ✅ `DashboardController`'s "continue where you left off" card skips missions
  the pilot's plan no longer reaches, so a downgrade cannot leave a link
  pointing straight at a 403.
- ✅ Seeded split, per decision 1 as chosen: Drone Basics, Precision Flight and
  Sensor Flight sit in the Starter tier and are browsable; Drone Basics' five
  missions are the five a Starter pilot can fly; Precision Flight and Sensor
  Flight override every mission to Pro; Delivery Ops and City Operations are
  Pro outright and inherit it. `pricing.md` reworded to "3 beginner courses
  (5 missions)" and its comparison table corrected to "3 of 5".
- ✅ **Test:** 18 new cases across `CourseTest`, `ChallengeTest`,
  `DronePhotoTest`, `DashboardTest`, `CourseSeederTest` and `EntitlementTest`.
  `test_the_starter_split_matches_the_pricing_copy` asserts the sold numbers
  against the seeded data, so the copy and the catalog cannot drift apart
  silently.

Two things worth knowing about the shape that landed:

- **Course pages are open to everyone; the gate is per mission.** A course's
  `required_plan` sets its missions' default tier and badges its catalog card —
  it does not gate the page. A Starter pilot can read every briefing in
  Precision Flight and see exactly what they are missing, which is the whole
  conversion argument. Access is enforced where the flying happens.
- **The lock badge is not a link yet.** The pricing page arrives in Phase 3, so
  `PlanLockBadge` renders a badge and `PlanUpgradeHint` a line of text, with no
  href. Phase 3 turns both into the upgrade CTA.

---

## Phase 3 — Pricing page and checkout — ✅ done

- ✅ Public `GET /pricing` → `PricingController` → `resources/js/pages/pricing.tsx`,
  driven by `BuildPricingCatalog`. Standalone layout alongside `welcome`: the
  app shell assumes a signed-in pilot and would break for the guests this page
  exists to convert.
- ✅ Monthly/annual toggle. The 17% saving is **computed** from the two amounts
  rather than quoted, so the claim cannot outlive the prices it describes.
- ✅ Checkout: `POST /checkout` → `CheckoutRequest` → `StartCheckout` →
  `ResolveCheckoutPrice`. The request carries a plan and a billing period and
  nothing else — no amount, no price ID — and answers JSON that the page hands
  straight to `Paddle.Checkout.open`. `test_the_client_is_never_handed_a_price_id`
  asserts the page body contains none.
- ✅ `settings/billing` beside the existing settings pages: current plan, next
  bill date, payment method, cancel/resume with a confirmation modal and toasts,
  and receipts.
- ✅ Team and Enterprise point at `SALES_EMAIL`, and `Plan::isSelfServe()` makes
  that structural: `test_checkout_refuses_a_sales_led_tier` fails if anyone ever
  tries to open checkout on them from somewhere else.
- ✅ **Test:** `PricingTest` and `BillingTest`, plus `PlanTest`. Guest, Starter
  and Pro viewers; every CTA state; checkout accepted and refused four ways;
  billing for each plan source; cancel, resume, receipts and payment method with
  Paddle faked and `preventStrayRequests()` on.

Four things worth knowing about the shape that landed:

- **The plan called for handing the client a `_ptxn`; it hands over Paddle.js
  options instead.** Cashier's `$user->checkout()` returns an option bag, not a
  server-created transaction — `_ptxn` is what Paddle's own hosted flow uses.
  The security property the plan wanted is unchanged and is what the tests
  assert: the browser never sees a price ID and has nothing to substitute.
- **Display amounts live in `config/plans.php` beside the price IDs.** Paddle's
  price preview API would remove the duplication, but it needs live credentials
  to answer, which would make the pricing page unrenderable in tests and in any
  environment without a Paddle account.
  `test_every_offered_billing_period_carries_a_display_amount` stops a variant
  being offered with no number against it.
- **The webhook listeners in the original plan were not built, because there is
  nothing to invalidate.** Phase 1 memoised the resolved plan on the `User`
  instance rather than in a cache, so a fresh instance per request already
  resolves from the subscriptions table. `PaddleWebhookTest` asserts the flip
  end to end.
- **The buyer's plan catches up on its own.** Paddle's overlay closes when
  Paddle has the money, which is before the webhook granting the plan has
  necessarily arrived. `usePaddle` takes an `onCompleted` callback, and the
  pricing page uses it to reload props at 2s, 5s and 10s, stopping as soon as
  the plan changes. It is a cue to go and look for the entitlement, never
  evidence of one — the server remains the only thing that grants it.

---

## Phase 4 — Launch offers

- `app/Actions/ResolveLaunchPricing.php` — counts **paying customers** (decision
  3) and returns discounted Pro price IDs while under 100.
- Applied on the server inside `ResolveCheckoutPrice`, which is the single place
  a price ID is chosen, so the displayed price and the charged price cannot
  diverge. The `monthly_launch` / `yearly_launch` variants and their $15/$150
  amounts are already configured; only the count and the swap are missing.
- Countdown / "N spots left" on the pricing page.

**Test:** boundary test at customer 99, 100 and 101.

---

## Phase 5 — Pro features

The long pole. Each is independently shippable; ordered by conversion value per
unit of effort.

1. **Python runtime** — Pyodide in the existing simulator worker
   (`resources/js/lib/simulator/worker.ts`), exposing the same drone API surface
   as JS. Largest bundle-size decision on the list; lazy-load it like the
   existing three.js split.
2. **Premium certificates** — server-rendered PDF on course completion. Cheap to
   build, high perceived value, and Starter's "basic certificates" already
   implies the plumbing.
3. **Advanced analytics** — per-mission attempt curves, score history, weak-spot
   breakdown. Builds on `user_challenge_progress`, which already carries
   attempts, best score and stars.
4. **Downloadable projects** — export a mission's code and telemetry.
5. **Drone configuration editor** — expose the physics constants in
   `resources/js/lib/simulator/physics.ts` as a saved per-user profile.
6. **Mission Builder** — user-authored missions writing the same
   `environment` / `success_criteria` JSON the seeder produces. Needs its own
   design pass: authoring UI, validation, sharing, moderation.

Every item on this list is a fixed cost to build and free to serve, which is
what makes a flat monthly price safe to sell. Anything metered — an AI
assistant, hosted compute, storage past a threshold — needs a credit ledger
designed before the feature, not after.

---

## Phase 6 — Team

- `teams`, `team_user` (with an instructor/student role), `assignments`,
  `assignment_submissions`. Every model gets `uuid` route keys per convention.
- Seat billing: base subscription covers 10, additional seats at $5/mo via a
  Paddle quantity-based subscription item — increment/decrement on invite and
  removal.
- Instructor dashboard, student analytics, shared workspaces, private classrooms.
- Team membership becomes a resolution branch in `ResolvePlanForUser` (already
  stubbed in Phase 1).

Note the margin shape: Team is $59 for ten seats against $19 for one Pro seat —
a ~69% education discount. Treated as deliberate: schools are the audience this
tier exists for, ten Pro seats at list price is not a number a classroom budget
clears, and the discount buys a whole cohort of pilots at once. Revisit it when
seat billing is actually built, not before — nothing depends on it until then.

---

## Phase 7 — Enterprise

Sales-led, so build against a signed contract rather than speculatively.
SSO (SAML/OIDC), REST API with versioned resources and token auth, LMS
integration (LTI), custom branding, private deployment. A contact-sales form and
a lead pipeline are the only pieces worth building before the first deal.

---

## Suggested sequencing

| Phases | Outcome |
|---|---|
| 0–2 | ✅ Entitlements exist and the catalog is gated. Nothing is for sale yet. |
| 3 | ✅ Pricing page, checkout and billing settings are built. Taking money now needs Paddle credentials, not code. |
| 4 | Launch pricing, narrowing `ResolveCheckoutPrice`. |
| 5 | Pro's feature list becomes true. Run alongside Phase 3–4 revenue. |
| 6 | Team opens. |
| 7 | Enterprise, contract-first. |

The one ordering rule: **do not open checkout on a tier before its features
exist.** Phase 3 deliberately routes Team and Enterprise to contact-sales for
exactly that reason.
