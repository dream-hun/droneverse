export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

/** Mirrors App\Enums\Plan. */
export type PlanValue = 'starter' | 'pro' | 'team' | 'enterprise';

/** Mirrors App\Enums\Feature. */
export type FeatureValue =
    | 'python_runtime'
    | 'mission_builder'
    | 'drone_config_editor'
    | 'premium_certificates'
    | 'advanced_analytics'
    | 'downloadable_projects'
    | 'team_management'
    | 'classroom_tools'
    | 'api_access'
    | 'sso'
    | 'priority_support'
    | 'beta_access';

export type Plan = {
    value: PlanValue;
    label: string;
    isPaid: boolean;
};

export type Auth = {
    user: User;
    /** The viewer's resolved plan. Guests resolve to Starter. */
    plan: Plan;
    /**
     * The features that plan grants. A rendering hint for locks and upgrade
     * CTAs — the server gates every capability regardless of what is listed
     * here, so never treat its presence as authorisation.
     */
    features: FeatureValue[];
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
