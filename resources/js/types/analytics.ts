/**
 * The shapes App\Queries\FlightLog returns.
 *
 * Every one of these is a projection over App\Models\ChallengeRun — the
 * append-only record of what each flight actually achieved — and not over the
 * merged progress row, which keeps only a pilot's best and so cannot say how
 * they got there.
 */

/** One graded run, as a point on a mission's attempt curve. */
export type CurvePoint = {
    /**
     * The run's position in the pilot's full history on this mission, not its
     * index in this window. A curve showing the last fifty of two hundred
     * runs still labels them 151–200.
     */
    attempt: number;
    score: number;
    /** The pilot's best score as at this run, so a plateau is visible. */
    best: number;
    stars: number;
    completed: boolean;
    collisions: number;
    elapsedSeconds: number;
    objectivesHit: number;
    objectivesTotal: number;
    flownAt: string;
};

export type FlightSummary = {
    runs: number;
    missionsFlown: number;
    missionsCleared: number;
    /** Cleared over flown, 0–1. */
    clearRate: number;
    /**
     * Runs taken to clear a mission, averaged over the ones cleared. Null
     * until the pilot has cleared something — a different statement from 0.
     */
    meanAttemptsToClear: number | null;
    flightSeconds: number;
    /** Runs finished without a collision, over all runs, 0–1. */
    cleanRunRate: number;
    bestScore: number;
};

/** A mission the pilot has flown, as listed in the curve selector. */
export type FlownMission = {
    challengeTitle: string;
    challengeSlug: string;
    courseTitle: string;
    courseSlug: string;
    runs: number;
    cleared: boolean;
};

/** A mission costing the pilot more attempts than the rest. */
export type WeakSpot = {
    challengeTitle: string;
    challengeSlug: string;
    courseTitle: string;
    courseSlug: string;
    runs: number;
    bestScore: number;
    maxScore: number;
    cleared: boolean;
    meanCollisions: number;
};

/** Where a pilot's best on one mission sits against every pilot's. */
export type MissionCohort = {
    /** Share of pilots this pilot's best beats outright, 0–100. */
    percentile: number;
    pilots: number;
    yourBest: number;
    topBest: number;
};
