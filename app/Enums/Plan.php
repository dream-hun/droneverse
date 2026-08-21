<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A subscription tier, as sold in docs/pricing.md.
 *
 * A plan answers two independent questions, and they are deliberately not the
 * same method. `features()` says which capabilities are unlocked. `covers()`
 * says how deep into the catalogue the plan reaches. Team ranks above Pro on
 * both, but the two axes are not the same and are not collapsed: a tier can
 * include every mission without including an instructor dashboard.
 */
enum Plan: string
{
    case Starter = 'starter';
    case Pro = 'pro';
    case Team = 'team';

    /**
     * The plan a subscribed price ID grants.
     *
     * "Price ID" is the provider-neutral name for whatever identifies the thing
     * being sold, and under Creem that is a product ID — the value read off a
     * subscription's `product_id` column. Creem has no separate price object: a
     * product carries its own amount and billing period, so one product is one
     * purchasable price. It is not this application's sense of "variant", which
     * is a billing period; see variantFor().
     *
     * The reverse of priceIds(), and the branch ResolvePlanForUser leans on
     * most. An unrecognized price ID resolves to null rather than to a default
     * plan: an ID we cannot account for is a configuration error, and silently
     * granting Pro for it would be worse than granting nothing.
     *
     * An ID claimed by more than one plan is the same kind of error and gets the
     * same answer. config/plans.php requires these to be unique across plans and
     * nothing enforces it, so the same product ID pasted under two tiers is one
     * slip in one `.env` file. Returning the first match would answer it out of
     * the order the cases happen to be declared in — which nobody reading this
     * file thinks of as billing logic, and which would quietly grant Pro to
     * every Team subscriber if the declarations were ever reordered. There is no
     * honest answer to "which plan did they buy" when one ID sells two, and the
     * ID sells exactly one thing in the Creem account whatever this
     * configuration claims, so nobody is entitled by it until it is fixed.
     */
    public static function fromPriceId(?string $priceId): ?self
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        $matches = array_values(array_filter(
            self::cases(),
            static fn (self $plan): bool => in_array($priceId, $plan->priceIds(), true),
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    public function label(): string
    {
        return match ($this) {
            self::Starter => 'Starter',
            self::Pro => 'Pro',
            self::Team => 'Team',
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
            self::Team => 'Everything Pro gives one pilot, on its way to a whole classroom.',
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
     * bullet describing something unbuilt says so in its own words. That is not
     * hypothetical: Team is on sale before its classroom tools exist, and a card
     * promising an instructor dashboard that opens nowhere is the difference
     * between a roadmap and a chargeback.
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
                'Pick your airframe on any mission — five drones, five envelopes',
                'Mission Builder — coming soon',
                /*
                 * Analytics and certificates were one bullet until analytics
                 * shipped. Leaving them paired would have made the built half
                 * vouch for the unbuilt one, which is the failure this list's
                 * docblock exists to prevent — so they are two lines now, and
                 * only one of them promises anything.
                 */
                'Advanced analytics on every run you fly',
                'Premium certificates — coming soon',
                'Priority support and beta access',
            ],
            /*
             * Team is sold today and its classroom tools are not built yet, so
             * every bullet that describes one says so. The tier is worth buying
             * on the first two lines alone; the rest is a roadmap, and a card
             * that presented it as shipped would be selling a screen nobody can
             * open. The "coming soon" wording is the same phrase the capability
             * grid uses for an unavailable Feature, so the two agree.
             */
            self::Team => [
                'Everything in Pro',
                'Priority support and beta access',
                'Instructor dashboard and student analytics — coming soon',
                'Seats for 10 students, assignments and shared workspaces — coming soon',
                '$5/month per additional seat, once seats ship',
            ],
        };
    }

    /**
     * The billing periods a buyer chooses between, most frequent first.
     *
     * Empty for a plan nobody checks out of.
     *
     * @return array<int, string>
     */
    public function variants(): array
    {
        return match ($this) {
            self::Pro, self::Team => ['monthly', 'yearly'],
            self::Starter => [],
        };
    }

    /**
     * What a variant costs, in minor units of the configured currency.
     *
     * Display only. Nothing that charges a card reads this — see priceId().
     */
    public function amount(string $variant): ?int
    {
        $amount = config('plans.amounts.'.$this->value.'.'.$variant);

        return is_int($amount) ? $amount : null;
    }

    /**
     * Every capability this plan unlocks.
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
            self::Team => [...$pro, Feature::TeamManagement, Feature::ClassroomTools],
        };
    }

    public function hasFeature(Feature $feature): bool
    {
        return in_array($feature, $this->features(), true);
    }

    /**
     * Whether a viewer on this plan may reach content requiring `$required`.
     *
     * Catalogue depth only. Team sits above Pro here because it includes
     * everything Pro sells, not because it unlocks extra missions — which is
     * why this is not the same question as features().
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
     * Every paid tier is bought with a card: a classroom of ten is a card
     * payment like any other, and docs/pricing.md has always promised the
     * upgrade is available at any time. Team's seat pricing is bought the same
     * way once Phase 7 lands — a second subscription against the seat variants
     * — which does not change how the base tier is sold.
     *
     * Starter is the only no. It is an account rather than a purchase and has
     * no price for a card form to charge, which is what makes it the fallback
     * a request with an unreadable plan resolves to: refused here rather than
     * charged for a tier we misread.
     */
    public function isSelfServe(): bool
    {
        return match ($this) {
            self::Pro, self::Team => true,
            self::Starter => false,
        };
    }

    /**
     * The Creem product ID selling a given billing period of this plan.
     *
     * Returns null when the variant is unconfigured, which is the normal state
     * in tests and on a fresh checkout. Callers that are about to charge must
     * treat null as fatal rather than falling through to a default price.
     */
    public function priceId(string $variant): ?string
    {
        $priceId = $this->priceIds()[$variant] ?? null;

        return is_string($priceId) && $priceId !== '' ? $priceId : null;
    }

    /**
     * Which variant of this plan a price ID sells.
     *
     * The reverse of priceId(), and how a stored subscription is read back as a
     * billing period the settings page can name.
     */
    public function variantFor(?string $priceId): ?string
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        $variant = array_search($priceId, $this->priceIds(), true);

        return is_string($variant) ? $variant : null;
    }

    /**
     * Every configured variant of this plan, keyed by variant name.
     *
     * @return array<string, string>
     */
    public function priceIds(): array
    {
        /** @var array<string, mixed> $configured */
        $configured = config('plans.prices.'.$this->value, []);

        return array_filter(
            $configured,
            static fn (mixed $priceId): bool => is_string($priceId) && $priceId !== '',
        );
    }

    /**
     * How deep into the catalogue the plan reaches.
     */
    private function catalogRank(): int
    {
        return match ($this) {
            self::Starter => 0,
            self::Pro => 1,
            self::Team => 2,
        };
    }
}
