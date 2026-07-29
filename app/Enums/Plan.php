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
    case Enterprise = 'enterprise';

    /**
     * The plan a subscribed Paddle price ID grants.
     *
     * The reverse of priceIds(), and the branch ResolvePlanForUser leans on
     * most. An unrecognized price ID resolves to null rather than to a default
     * plan: an ID we cannot account for is a configuration error, and silently
     * granting Pro for it would be worse than granting nothing.
     */
    public static function fromPriceId(?string $priceId): ?self
    {
        if ($priceId === null || $priceId === '') {
            return null;
        }

        foreach (self::cases() as $plan) {
            if (in_array($priceId, $plan->priceIds(), true)) {
                return $plan;
            }
        }

        return null;
    }

    public function label(): string
    {
        return match ($this) {
            self::Starter => 'Starter',
            self::Pro => 'Pro',
            self::Team => 'Team',
            self::Enterprise => 'Enterprise',
        };
    }

    /**
     * The one-line pitch under the plan's name on the pricing page.
     */
    public function tagline(): string
    {
        return match ($this) {
            self::Starter => 'Everything you need to find out whether flying code is for you.',
            self::Pro => 'The whole catalogue, every language, and the tools that come with them.',
            self::Team => 'Pro for a classroom, with the dashboard an instructor actually needs.',
            self::Enterprise => 'Your own deployment, your own identity provider, your own terms.',
        };
    }

    /**
     * The bullets the plan's card sells itself on.
     *
     * Deliberately not derived from features(): a card sells outcomes, and half
     * of what a card should say — catalogue depth, support, seat counts — is not
     * a Feature case at all. The capability grid below the cards is the place
     * that reads features(), and it is the place that has to stay honest about
     * what has shipped.
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
                'JavaScript and Python',
                'Mission Builder and the drone configuration editor',
                'Premium certificates and advanced analytics',
                'Priority support and beta access',
            ],
            self::Team => [
                'Everything in Pro for up to 10 students',
                'Instructor dashboard and student analytics',
                'Assignments, shared workspaces, private classrooms',
                '$5/month per additional seat',
            ],
            self::Enterprise => [
                'Unlimited users and teams',
                'SSO, REST API and LMS integration',
                'Custom branding and private deployment',
                'Dedicated account manager and an SLA',
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
            self::Starter, self::Enterprise => [],
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
            Feature::PythonRuntime,
            Feature::MissionBuilder,
            Feature::DroneConfigEditor,
            Feature::PremiumCertificates,
            Feature::AdvancedAnalytics,
            Feature::DownloadableProjects,
            Feature::PrioritySupport,
            Feature::BetaAccess,
        ];

        $team = [...$pro, Feature::TeamManagement, Feature::ClassroomTools];

        return match ($this) {
            self::Starter => [],
            self::Pro => $pro,
            self::Team => $team,
            self::Enterprise => [...$team, Feature::ApiAccess, Feature::Sso],
        };
    }

    public function hasFeature(Feature $feature): bool
    {
        return in_array($feature, $this->features(), true);
    }

    /**
     * Whether a viewer on this plan may reach content requiring `$required`.
     *
     * Catalogue depth only. Team and Enterprise sit above Pro here because they
     * include everything Pro sells, not because they unlock extra missions —
     * which is why this is not the same question as features().
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
     * Whether checkout may be opened for this plan, or whether its CTA has to
     * point at contact-sales instead.
     *
     * Team and Enterprise sell classroom tools and SSO, neither of which exists
     * until Phases 7-8. Taking money for them before then is a chargeback, so
     * the ordering rule is enforced here rather than trusted to the pricing
     * page's markup.
     */
    public function isSelfServe(): bool
    {
        return match ($this) {
            self::Pro => true,
            self::Starter, self::Team, self::Enterprise => false,
        };
    }

    /**
     * The Paddle price ID selling a given variant of this plan.
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
            self::Enterprise => 3,
        };
    }
}
