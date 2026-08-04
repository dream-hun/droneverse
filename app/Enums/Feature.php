<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A capability a plan either grants or withholds.
 *
 * Every case is registered as a Gate ability under its own value, so both
 * `$user->can(Feature::PythonRuntime->value)` and
 * `->middleware('can:python_runtime')` work without a bespoke check. Adding a
 * case here and listing it on the relevant plans in App\Enums\Plan is all a new
 * gated capability needs.
 *
 * Cases describe capabilities, not catalogue size. How many courses and
 * missions a plan can reach is answered by the `required_plan` column in
 * Phase 2, not by a feature flag.
 *
 * There is deliberately no AI assistant case. Every capability here is a fixed
 * cost to build and free to serve; an AI assistant is the one that would carry
 * a per-call marginal cost, and metering it needs a credit ledger before it
 * needs a flag. Adding the case back means building that ledger with it.
 */
enum Feature: string
{
    case PythonRuntime = 'python_runtime';
    case MissionBuilder = 'mission_builder';
    case DroneConfigEditor = 'drone_config_editor';
    case PremiumCertificates = 'premium_certificates';
    case AdvancedAnalytics = 'advanced_analytics';
    case DownloadableProjects = 'downloadable_projects';
    case TeamManagement = 'team_management';
    case ClassroomTools = 'classroom_tools';
    case ApiAccess = 'api_access';
    case Sso = 'sso';
    case PrioritySupport = 'priority_support';
    case BetaAccess = 'beta_access';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $feature): string => $feature->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::PythonRuntime => 'Python Runtime',
            self::MissionBuilder => 'Mission Builder',
            self::DroneConfigEditor => 'Drone Configuration Editor',
            self::PremiumCertificates => 'Premium Certificates',
            self::AdvancedAnalytics => 'Advanced Analytics',
            self::DownloadableProjects => 'Downloadable Projects',
            self::TeamManagement => 'Team Management',
            self::ClassroomTools => 'Classroom Tools',
            self::ApiAccess => 'API Access',
            self::Sso => 'Single Sign-On',
            self::PrioritySupport => 'Priority Support',
            self::BetaAccess => 'Beta Access',
        };
    }

    /**
     * Whether the capability behind this flag has actually shipped.
     *
     * The comparison table in docs/pricing.md sells several features that are
     * still unbuilt. Gating them now is correct — the gate is what the build
     * lands behind — but the pricing page needs to distinguish "your plan does
     * not include this" from "nobody has this yet", and Phase 3 reads this to
     * do it. Flip a case to true in the phase that ships it.
     */
    public function isAvailable(): bool
    {
        return match ($this) {
            self::PrioritySupport, self::BetaAccess, self::AdvancedAnalytics => true,
            self::PythonRuntime,
            self::MissionBuilder,
            self::DroneConfigEditor,
            self::PremiumCertificates,
            self::DownloadableProjects => false,
            self::TeamManagement, self::ClassroomTools => false,
            self::ApiAccess, self::Sso => false,
        };
    }
}
