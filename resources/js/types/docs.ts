/** One argument of a `drone.*` command, as documented in config/drone-api.php. */
export type CommandParam = {
    name: string;
    type: string;
    optional: boolean;
    description: string;
};

export type CommandDoc = {
    name: string;
    signature: string;
    summary: string;
    params: CommandParam[];
    /** Null when the call resolves with nothing useful and is awaited for the manoeuvre alone. */
    returns: string | null;
    notes: string[];
};

/** Commands broken under the reference's own headings: Flight, Sensors, Camera. */
export type CommandGroup = {
    key: string;
    label: string;
    commands: CommandDoc[];
};

export type CodeExample = {
    slug: string;
    title: string;
    description: string;
    code: string;
};

export type Pitfall = {
    title: string;
    body: string;
};

export type CourseDocumentation = {
    tagline: string;
    summary: string;
    objectives: string[];
    commandGroups: CommandGroup[];
    examples: CodeExample[];
    pitfalls: Pitfall[];
};

/** A mission link in the docs page index — no progress, that lives on the course page. */
export type DocumentedMission = {
    title: string;
    slug: string;
    /** Listed either way, but only linked when the viewer's plan reaches it. */
    locked: boolean;
};

/** A thing true of every mission, not just one course's. */
export type ManualConcept = {
    title: string;
    body: string;
};

/** The course a group of examples or pitfalls was written for. */
export type ManualCourse = {
    title: string;
    slug: string;
    /** Unpublished courses are still named, but are not linked to. */
    published: boolean;
};

export type ManualExampleGroup = {
    course: ManualCourse;
    /** Slugs are prefixed with the course, so two courses cannot share an id. */
    items: CodeExample[];
};

export type ManualPitfallGroup = {
    course: ManualCourse;
    items: Pitfall[];
};

/** One line of the scoring table, with the points it is worth. */
export type ScoringWeight = {
    label: string;
    points: number;
    note: string;
};

/**
 * How a run is graded. Every number here is read off
 * `App\Actions\GradeSimulatorRun`, never restated, so the page cannot promise
 * one policy while the grader applies another.
 */
export type ManualScoring = {
    weights: ScoringWeight[];
    /** What the weights add up to before the mission's own maximum scales it. */
    total: number;
    collisionPenalty: number;
    completion: string;
    scaling: string;
    /** In the order they are earned; the length is the maximum. */
    stars: string[];
};

/** The whole reference, as `/docs` receives it. Mirrors BuildDroneManual. */
export type DroneManual = {
    tagline: string;
    summary: string;
    concepts: ManualConcept[];
    commandGroups: CommandGroup[];
    examples: ManualExampleGroup[];
    pitfalls: ManualPitfallGroup[];
    scoring: ManualScoring;
};
