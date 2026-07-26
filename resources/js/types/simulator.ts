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
    /** Optional callsign surfaced by `drone.scan()` (e.g. "north-tower"). */
    label?: string;
};

/** Street-level scenery that is solid, collidable, and scannable. */
export type PropConfig = {
    kind: 'car' | 'van' | 'tree';
    x: number;
    z: number;
    rotationY?: number;
    /** Body paint for vehicles; ignored by trees. */
    color?: string;
    /** Optional callsign surfaced by `drone.scan()` (e.g. "delivery-van"). */
    label?: string;
};

/** Drive-through wash tunnel; the drone must fly in one end and out the other. */
export type CarwashConfig = {
    x: number;
    z: number;
    rotationY?: number;
    /** Interior opening width in meters. */
    width?: number;
    /** Interior opening height in meters. */
    height?: number;
    /** Tunnel length along its local -Z axis in meters. */
    length?: number;
    label?: string;
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
    /** Optional ambient wind; challenges without it get a gentle default breeze. */
    wind?: { speed?: number; directionDeg?: number };
    /** Optional city scenery: parked vehicles and street trees. */
    props?: PropConfig[];
    /** Optional drone wash tunnel. */
    carwash?: CarwashConfig;
};

/** A spot that must appear in at least one captured photo (2D match). */
export type PhotoTargetConfig = {
    x: number;
    z: number;
    radius: number;
    label?: string;
};

export type SuccessCriteria = {
    type: 'waypoints' | 'gates';
    waypoints: WaypointConfig[];
    avoid_collisions: boolean;
    max_time_seconds: number;
    landing_required: boolean;
    min_altitude?: number;
    /** Require at least this many photos captured during the run. */
    min_photos?: number;
    /** Each target needs one photo taken within its radius. */
    photo_targets?: PhotoTargetConfig[];
    /** Require a full pass through the wash tunnel. */
    wash_required?: boolean;
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

/** One pilot's row on the leaderboard. */
export type LeaderboardStanding = {
    /** Shared by pilots level on points, stars and completions alike. */
    rank: number;
    name: string;
    /** Total best score across every published challenge in scope. */
    points: number;
    stars: number;
    completed: number;
    /** Set server-side so the board never ships other pilots' user ids. */
    isYou: boolean;
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

/**
 * The mission's reference solution. `code` is only ever sent once the pilot
 * has unlocked it, so a locked panel has nothing to reveal.
 */
export type ChallengeSolution = {
    exists: boolean;
    unlocked: boolean;
    code: string | null;
    attemptsRequired: number;
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
    photosTaken: number;
    photoTargetsHit: number;
    photoTargetsTotal: number;
    /** Photos still needed to satisfy `min_photos` (0 when met or unset). */
    photosMissing: number;
    washRequired: boolean;
    washed: boolean;
};

/** A saved drone photo as serialized for the photo log page. */
export type DronePhotoSummary = {
    id: number;
    url: string;
    label: string | null;
    challengeTitle: string | null;
    courseSlug: string | null;
    challengeSlug: string | null;
    position: { x: number; y: number; z: number; headingDeg: number } | null;
    takenAt: string;
};
