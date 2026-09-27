<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A subscription tier, as sold on the pricing page.
 *
 * A plan answers two independent questions, and they are deliberately not the
 * same method. `features()` says which capabilities are unlocked. `covers()`
 * says how deep into the catalogue the plan reaches.
 *
 * Pro is the only tier for sale, through Kelviq; see kelviq.config.ts for the
 * catalog it is sold from. Starter is not a Kelviq plan at all — it is the
 * account every pilot has until Kelviq says otherwise.
 */
enum Plan: string
{
    case Starter = 'starter';
    case Pro = 'pro';

    public function label(): string
    {
        return match ($this) {
            self::Starter => 'Starter',
            self::Pro => 'Pro',
        };
    }

    /**
     * The one-line pitch under the plan's name on the pricing page.
     */
    public function tagline(): string
    {
        return match ($this) {
            self::Starter => 'Everything you need to find out whether flying code is for you.',
            self::Pro => 'The whole catalogue, every mission in it, and the tools that come with them.',
        };
    }

    /**
     * The bullets the plan's card sells itself on.
     *
     * Deliberately not derived from features(): a card sells outcomes, and half
     * of what a card should say — catalogue depth, support, seat counts — is not
     * a Feature case at all. The capability grid below the cards is the one that
     * reads features(), and it is the one that marks an unbuilt capability
     * automatically.
     *
     * Which means these bullets have to keep themselves honest, by hand, and a
     * bullet describing something unbuilt says so in its own words: a card
     * promising a tool that opens nowhere is the difference between a roadmap
     * and a chargeback.
     *
     * @return array<int, string>
     */
    public function highlights(): array
    {
        return match ($this) {
            self::Starter => [
                '3 beginner courses, 5 flyable missions',
                'Browse every briefing in those courses',
                'JavaScript, progress tracking, community support',
                'Basic achievement certificates',
            ],
            self::Pro => [
                'Every course and every mission',
                'Pick your airframe on any mission: five drones, five envelopes',
                'Mission Builder (coming soon)',
                /*
                 * Analytics and certificates were one bullet until analytics
                 * shipped. Leaving them paired would have made the built half
                 * vouch for the unbuilt one, which is the failure this list's
                 * docblock exists to prevent — so they are two lines now, and
                 * only one of them promises anything.
                 */
                'Advanced analytics on every run you fly',
                'Premium certificates (coming soon)',
                'Priority support and beta access',
            ],
        };
    }

    /**
     * The ways a buyer can pay for this plan, most frequent first: monthly,
     * yearly, or once for good.
     *
     * Empty for a plan nobody checks out of.
     *
     * @return array<int, string>
     */
    public function variants(): array
    {
        return match ($this) {
            self::Pro => ['monthly', 'yearly', 'lifetime'],
            self::Starter => [],
        };
    }

    /**
     * What a variant costs, in minor units of the configured currency.
     *
     * Display only. Nothing that charges a card reads this: Kelviq charges what
     * kelviq.config.ts says, and config/plans.php has to be kept in step with
     * it by hand.
     */
    public function amount(string $variant): ?int
    {
        $amount = config('plans.amounts.'.$this->value.'.'.$variant);

        return is_int($amount) ? $amount : null;
    }

    /**
     * Every capability this plan unlocks.
     *
     * What the pricing page advertises, and what a `plan_override` grants. A
     * pilot paying through Kelviq is granted whatever Kelviq's entitlements say
     * instead — see App\Actions\ResolveFeaturesForUser — so a capability that
     * ships has to be added to kelviq.config.ts as well as listed here.
     *
     * @return array<int, Feature>
     */
    public function features(): array
    {
        $pro = [
            Feature::MissionBuilder,
            Feature::DroneConfigEditor,
            Feature::PremiumCertificates,
            Feature::AdvancedAnalytics,
            Feature::DownloadableProjects,
            Feature::PrioritySupport,
            Feature::BetaAccess,
        ];

        return match ($this) {
            self::Starter => [],
            self::Pro => $pro,
        };
    }

    public function hasFeature(Feature $feature): bool
    {
        return in_array($feature, $this->features(), true);
    }

    /**
     * Whether a viewer on this plan may reach content requiring `$required`.
     *
     * Catalogue depth only, which is why this is not the same question as
     * features().
     */
    public function covers(self $required): bool
    {
        return $this->catalogRank() >= $required->catalogRank();
    }

    public function isPaid(): bool
    {
        return $this !== self::Starter;
    }

    /**
     * Whether checkout may be opened for this plan.
     *
     * Starter is the only no. It is an account rather than a purchase and has
     * no price for a card form to charge, which is what makes it the fallback
     * a request with an unreadable plan resolves to: refused here rather than
     * charged for a tier we misread.
     */
    public function isSelfServe(): bool
    {
        return match ($this) {
            self::Pro => true,
            self::Starter => false,
        };
    }

    /**
     * The identifier of the Kelviq plan that sells a variant of this tier.
     *
     * Lifetime is its own Kelviq plan, `pro-lifetime`, so a discount scoped to
     * the subscription never applies to the one-time sale; both grant the same
     * entitlements, so both resolve to Pro. Null for a variant the plan does
     * not sell, and for Starter, which Kelviq does not sell at all. Each value
     * is the `identifier` of a plan() in kelviq.config.ts.
     */
    public function kelviqPlan(string $variant): ?string
    {
        if (! in_array($variant, $this->variants(), true)) {
            return null;
        }

        return $variant === 'lifetime' ? 'pro-lifetime' : 'pro';
    }

    /**
     * The Kelviq feature whose entitlement means a pilot is on this tier.
     *
     * Catalogue depth is not a Feature case — it is answered by `covers()` —
     * so it is sold as a feature of its own, and this is what
     * App\Actions\ResolvePlanForUser asks Kelviq about.
     */
    public function catalogFeature(): ?string
    {
        return match ($this) {
            self::Pro => 'full-catalog',
            self::Starter => null,
        };
    }

    /**
     * The Kelviq charge period a variant of this plan is sold on.
     *
     * Null for a variant the plan does not sell, which is how a checkout for
     * anything else is refused before it reaches Kelviq.
     */
    public function chargePeriod(string $variant): ?string
    {
        $period = ['monthly' => 'MONTHLY', 'yearly' => 'YEARLY', 'lifetime' => 'ONE_TIME'][$variant] ?? null;

        return in_array($variant, $this->variants(), true) ? $period : null;
    }

    /**
     * How deep into the catalogue the plan reaches.
     */
    private function catalogRank(): int
    {
        return match ($this) {
            self::Starter => 0,
            self::Pro => 1,
        };
    }
}
