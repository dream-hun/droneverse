export type ObstacleConfig = {
    type: 'box' | 'cylinder';
    x: number;
    y: number;
    z: number;
    sx?: number;
    sy?: number;
    sz?: number;
    radius?: number;
    height?: number;
    rotationY?: number;
};

export type GateConfig = {
    x: number;
    y: number;
    z: number;
    width: number;
    height: number;
    rotationY: number;
};

export type WaypointConfig = {
    x: number;
    y: number;
    z: number;
    radius: number;
};

export type EnvironmentConfig = {
    start: { x: number; y: number; z: number; yaw: number };
    bounds: { width: number; depth: number; height: number };
    obstacles: ObstacleConfig[];
    gates: GateConfig[];
    waypoints: WaypointConfig[];
    goal: { x: number; z: number; radius: number };
};

export type SuccessCriteria = {
    type: 'waypoints' | 'gates';
    waypoints: WaypointConfig[];
    avoid_collisions: boolean;
    max_time_seconds: number;
    landing_required: boolean;
    min_altitude?: number;
};

export type ChallengeStatus = 'not_started' | 'in_progress' | 'completed';

export type CourseSummary = {
    title: string;
    slug: string;
    /** Present on the course catalog; the dashboard's compact cards omit it. */
    description?: string;
    difficulty: string;
    challengesCount: number;
    completedCount: number;
};

export type CourseDetail = {
    title: string;
    slug: string;
    description: string;
    difficulty: string;
};

export type ChallengeSummary = {
    title: string;
    slug: string;
    briefing: string;
    difficulty: string;
    status: ChallengeStatus;
    bestScore: number;
    stars: number;
};

export type ChallengeDetail = {
    title: string;
    slug: string;
    briefing: string;
    difficulty: string;
    environment: EnvironmentConfig;
    successCriteria: SuccessCriteria;
    maxScore: number;
    starterCode: string;
};

export type ChallengeProgress = {
    status: ChallengeStatus;
    bestScore: number;
    stars: number;
    attempts: number;
    savedCode: string;
};

export type RunResult = {
    completed: boolean;
    score: number;
    stars: number;
    waypointsHit: number;
    waypointsTotal: number;
    collisions: number;
    landed: boolean;
    elapsedSeconds: number;
    timedOut: boolean;
};
