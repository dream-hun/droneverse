<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use LemonSqueezy\Laravel\Subscription;

beforeEach(function (): void {
    config([
        'plans.prices' => [
            'pro' => [
                'monthly' => 'var_pro_monthly',
                'yearly' => 'var_pro_yearly',
            ],
            'team' => ['monthly' => 'var_team_monthly'],
        ],
    ]);

    /*
     * The page is local reads and nothing else. It is rendered seconds after a
     * checkout, sometimes repeatedly while a webhook is in flight, so a
     * provider round trip here would be paid for over and over — a stray
     * request is a failure worth seeing.
     */
    Http::preventStrayRequests();
});

test('the thank-you page requires an account', function (): void {
    $this->get(route('subscription.thank-you'))->assertRedirect(route('login'));
});

/**
 * The state most buyers actually land in. The money has gone and the webhook
 * that grants the plan has not arrived, so the page claims nothing about the
 * plan and says only that something is being activated — `pending` is what it
 * polls on.
 */
test('a buyer whose webhook has not landed is told the plan is pending', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('subscription.thank-you'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('subscription/thank-you')
            ->where('pending', true)
            ->where('subscription', null)
            ->where('highlights', [])
            ->where('plan.value', 'starter'));

    Http::assertNothingSent();
});

test('a subscriber is shown the plan, period and renewal date they bought', function (): void {
    $user = User::factory()->create();
    confirmationSubscribe(
        $user,
        'var_pro_yearly',
        renewsAt: new DateTimeImmutable('2027-08-21T00:00:00+00:00'),
    );

    $this->actingAs($user)
        ->get(route('subscription.thank-you'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('subscription/thank-you')
            ->where('pending', false)
            ->where('plan.value', 'pro')
            ->where('plan.isPaid', true)
            ->where('subscription.planLabel', 'Pro')
            ->where('subscription.variant', 'yearly')
            ->where('subscription.renewsAt', '2027-08-21T00:00:00+00:00')
            ->where('subscription.cardBrand', 'visa')
            ->where('subscription.cardLastFour', '4242')
            ->where('highlights', Plan::Pro->highlights()));

    Http::assertNothingSent();
});

/**
 * The confirmation is built from the subscription rather than from anything the
 * browser sent, so a pilot who simply visits the URL reads the truth about
 * their own account — never a plan they have not paid for.
 */
test('the page cannot be talked into confirming a plan that was never bought', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('subscription.thank-you', ['plan' => 'team', 'pending' => false]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('plan.value', 'starter')
            ->where('pending', true)
            ->where('subscription', null)
            ->where('highlights', []));
});

/**
 * A comped account holds a paid plan with no subscription to confirm. Polling
 * for a row that will never be written would leave that pilot watching a
 * spinner for as long as they cared to wait, so they are not pending.
 */
test('a comped account is confirmed rather than left waiting', function (): void {
    $this->actingAs(User::factory()->onPlan(Plan::Pro)->create())
        ->get(route('subscription.thank-you'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pending', false)
            ->where('plan.value', 'pro')
            ->where('subscription', null)
            ->where('highlights', []));
});

/**
 * An expired subscription is not a confirmation. The page reports the pilot's
 * actual entitlement, and a lapsed one is Starter — but it does not poll for
 * that, because the row it would be waiting for is already here and finished.
 */
test('an expired subscription is neither confirmed nor polled for', function (): void {
    $user = User::factory()->create();
    confirmationSubscribe(
        $user,
        'var_pro_monthly',
        status: Subscription::STATUS_EXPIRED,
        endsAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
    );

    $this->actingAs($user)
        ->get(route('subscription.thank-you'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('plan.value', 'starter')
            ->where('subscription', null)
            ->where('highlights', [])
            /*
             * Pending in the sense the page means it: nothing granted, so it
             * keeps asking. There is no way to tell this apart from a webhook
             * still in flight without a checkout identifier the page
             * deliberately does not carry, and the poll gives up on its own.
             */
            ->where('pending', true));
});

/**
 * A trial has no card on file until something has been charged, and Lemon
 * Squeezy sends empty strings rather than nulls for that. Normalised here so
 * the page has one branch, not two.
 */
test('a trial reports its end date and no card', function (): void {
    $user = User::factory()->create();
    confirmationSubscribe(
        $user,
        'var_pro_monthly',
        status: Subscription::STATUS_ON_TRIAL,
        trialEndsAt: new DateTimeImmutable('2026-09-04T00:00:00+00:00'),
        cardBrand: '',
        cardLastFour: '',
    );

    $this->actingAs($user)
        ->get(route('subscription.thank-you'))
        ->assertInertia(fn ($page) => $page
            ->where('pending', false)
            ->where('subscription.onTrial', true)
            ->where('subscription.trialEndsAt', '2026-09-04T00:00:00+00:00')
            ->where('subscription.cardBrand', null)
            ->where('subscription.cardLastFour', null));
});

/**
 * Give the user a Lemon Squeezy subscription row.
 *
 * Built with the package's factory but saved by hand: its afterCreating hook
 * creates a customer per subscription, and nothing on this page reads one.
 */
function confirmationSubscribe(
    User $user,
    string $variantId,
    string $status = Subscription::STATUS_ACTIVE,
    ?DateTimeInterface $endsAt = null,
    ?DateTimeInterface $renewsAt = null,
    ?DateTimeInterface $trialEndsAt = null,
    ?string $cardBrand = 'visa',
    ?string $cardLastFour = '4242',
): Subscription {
    $subscription = Subscription::factory()->make([
        'billable_id' => $user->id,
        'billable_type' => $user->getMorphClass(),
        'type' => Subscription::DEFAULT_TYPE,
        'lemon_squeezy_id' => (string) fake()->unique()->randomNumber(8),
        'status' => $status,
        'product_id' => 'prod_pro',
        'variant_id' => $variantId,
        'card_brand' => $cardBrand,
        'card_last_four' => $cardLastFour,
        'renews_at' => $renewsAt,
        'ends_at' => $endsAt,
        'trial_ends_at' => $trialEndsAt,
    ]);

    $subscription->save();

    return $subscription;
}
