/**
 * The shapes the admin area's controllers send.
 *
 * Each mirrors a class under App\Http\Resources\Admin or an Action's return
 * docblock; the PHP side is the authority, and these exist so a renamed key
 * fails the type check rather than rendering `undefined`.
 */
import type { AdminPermissionValue, PlanValue } from '@/types/auth';

/** Mirrors App\Http\Resources\Admin\PaginationResource. */
export type Pagination = {
    page: number;
    lastPage: number;
    total: number;
    perPage: number;
};

export type Option<TValue extends string = string> = {
    value: TValue;
    label: string;
};

export type PilotRef = {
    uuid: string;
    name: string;
    email: string;
};

/** Mirrors App\Http\Resources\Admin\AdminUserResource. */
export type AdminUser = {
    uuid: string;
    name: string;
    email: string;
    emailVerified: boolean;
    /** What the pilot can reach right now, however they came by it. */
    plan: Option<PlanValue>;
    /** Set by hand; outranks billing when present. */
    planOverride: PlanValue | null;
    roles: string[];
    /** Null where the list did not count them. */
    runs: number | null;
    createdAt: string;
    can: { update: boolean; delete: boolean };
};

export type RoleOption = {
    name: string;
    isAdmin: boolean;
    /** Whether the viewer may hand this role out: they hold all it grants. */
    assignable: boolean;
};

/** What the user forms offer and what the viewer may change on them. */
export type UserFormOptions = {
    roles: RoleOption[];
    plans: Option<PlanValue>[];
    can: { assignRoles: boolean; assignAdminRole: boolean };
};

/** Mirrors App\Http\Resources\Admin\AdminRoleResource. */
export type AdminRole = {
    uuid: string;
    name: string;
    /** Holds every permission through the Gate; cannot be edited or deleted. */
    isAdmin: boolean;
    permissions: AdminPermissionValue[];
    users: number;
    /** From App\Policies\RolePolicy: only roles within the viewer's reach. */
    can: { update: boolean; delete: boolean };
};

export type PermissionOption = {
    value: AdminPermissionValue;
    label: string;
    description: string;
};

/** Mirrors App\Http\Resources\Admin\AdminCourseResource. */
export type AdminCourse = {
    slug: string;
    title: string;
    description: string;
    difficulty: string;
    requiredPlan: PlanValue;
    order: number;
    isPublished: boolean;
    challenges: number;
    publishedChallenges: number;
    quizzes: number;
};

/** Mirrors App\Http\Resources\Admin\AdminChallengeResource. */
export type AdminChallenge = {
    slug: string;
    title: string;
    briefing: string;
    order: number;
    difficulty: string;
    /** Null inherits the course's tier. */
    requiredPlan: PlanValue | null;
    starterCode: string;
    solutionCode: string | null;
    /** Pretty-printed JSON, edited as text. */
    environment: string;
    /** Pretty-printed JSON, edited as text. */
    successCriteria: string;
    maxScore: number;
    isPublished: boolean;
    /** Pilots holding progress on the mission. */
    pilots: number;
};

export type ActivityKind = 'signup' | 'run' | 'quiz';

/** One row of App\Queries\ActivityFeed. */
export type ActivityItem = {
    key: string;
    kind: ActivityKind;
    occurredAt: string;
    pilot: PilotRef | null;
    title: string;
    meta: string | null;
};

export type ActivityPage = {
    items: ActivityItem[];
    hasMore: boolean;
    page: number;
};

/** Mirrors App\Actions\BuildAdminOverview. */
export type AdminOverview = {
    pilots: {
        total: number;
        newThisWeek: number;
        unverified: number;
        staff: number;
    };
    flying: {
        runs: number;
        activePilots: number;
        /** Null on a day nobody flew — not the same as every run failing. */
        clearRate: number | null;
    };
    /** Null unless sessions are stored in the database. */
    online: number | null;
    daily: { date: string; signups: number; runs: number }[];
    busiestMissions: {
        challengeTitle: string;
        challengeSlug: string;
        courseTitle: string;
        courseSlug: string;
        runs: number;
        pilots: number;
        clearRate: number;
    }[];
};

export type HealthCheck = {
    name: string;
    ok: boolean;
    detail: string;
    latencyMs: number | null;
};

export type FailedJob = {
    id: string;
    connection: string;
    queue: string;
    job: string;
    exception: string;
    failedAt: string | null;
};

/** Mirrors App\Actions\BuildSystemReport. */
export type SystemReport = {
    environment: {
        name: string;
        environment: string;
        debug: boolean;
        maintenance: boolean;
        php: string;
        laravel: string;
        timezone: string;
        drivers: Record<string, string>;
    };
    checks: HealthCheck[];
    runtime: {
        memoryPeak: number;
        memoryLimit: string;
        loadAverage: number[] | null;
        opcache: boolean;
        diskFree: number | null;
        diskTotal: number | null;
    };
    queue: {
        connection: string;
        /** Null unless the queue is the database driver. */
        pending: number | null;
        oldestPendingSeconds: number | null;
        failed: number | null;
    };
    failedJobs: FailedJob[];
    tables: { name: string; rows: number | null; size: number | null }[];
    sessions: { last5Minutes: number | null; last60Minutes: number | null };
};

/** Mirrors App\Http\Resources\Admin\AdminQuizResource. */
export type AdminQuiz = {
    slug: string;
    title: string;
    description: string;
    order: number;
    /** Null inherits the course's tier. */
    requiredPlan: PlanValue | null;
    /** Whole percentage of questions a pilot must get right. */
    passPercentage: number;
    isPublished: boolean;
    questions: number;
    /** Pilots holding progress on the quiz. */
    pilots: number;
};

export type AdminQuizOption = {
    id: number;
    label: string;
    isCorrect: boolean;
};

/** Mirrors App\Http\Resources\Admin\AdminQuizQuestionResource. */
export type AdminQuizQuestion = {
    uuid: string;
    prompt: string;
    explanation: string | null;
    order: number;
    /** Derived on save from how many answers are marked correct. */
    type: 'single' | 'multiple';
    options: AdminQuizOption[];
};
