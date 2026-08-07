import type { PlanValue } from '@/types/auth';

/** Mirrors `App\Enums\QuizQuestionType`. */
export type QuizQuestionType = 'single' | 'multiple';

/** Mirrors `App\Enums\QuizStatus`. */
export type QuizStatus = 'not_started' | 'attempted' | 'passed';

/**
 * One selectable answer, as the quiz page receives it.
 *
 * There is no `isCorrect` here and there must never be one — the answer key
 * stays on the server until a submission has been graded. See
 * `App\Http\Resources\QuizDetailResource`.
 */
export type QuizChoice = {
    id: number;
    label: string;
};

export type QuizQuestionPrompt = {
    id: number;
    prompt: string;
    type: QuizQuestionType;
    /** Radios or checkboxes. Stated by the server so the widget cannot
     *  disagree with the grader about how many answers the question takes. */
    allowsMultiple: boolean;
    options: QuizChoice[];
};

/** A quiz as the take-it page reads it. Mirrors `QuizDetailResource`. */
export type QuizDetail = {
    title: string;
    slug: string;
    description: string;
    /** Percentage of questions that must be correct to pass. */
    passPercentage: number;
    questions: QuizQuestionPrompt[];
};

/** The viewer's standing on one quiz. Mirrors `QuizProgressResource`. */
export type QuizProgress = {
    status: QuizStatus;
    /** Best percentage the pilot has ever scored, 0 if never attempted. */
    bestScore: number;
    attempts: number;
    /** Whether the pilot has *ever* passed, not whether the last attempt did. */
    passed: boolean;
};

/** A quiz row on a course page. Mirrors `QuizSummaryResource`. */
export type QuizSummary = {
    title: string;
    slug: string;
    description: string;
    questionCount: number;
    passPercentage: number;
    /** The plan needed to take it, inherited from the course unless set. */
    requiredPlan: PlanValue;
    /** The viewer cannot take it: render an upgrade prompt, not a link. */
    locked: boolean;
    status: QuizStatus;
    bestScore: number;
    attempts: number;
    passed: boolean;
};

/**
 * How one question was answered, returned only after grading.
 *
 * This is the shape that carries the answer key, and the only one that ever
 * does.
 */
export type QuizQuestionResult = {
    id: number;
    correct: boolean;
    selectedOptionIds: number[];
    correctOptionIds: number[];
    explanation: string | null;
};

/** A graded submission. Mirrors `QuizResultResource`. */
export type QuizResult = {
    /** Percentage scored on *this* submission. */
    score: number;
    correctCount: number;
    questionCount: number;
    /** Whether this submission cleared the bar. */
    passed: boolean;
    questions: QuizQuestionResult[];
    /** The merged standing afterwards — `progress.passed` is "ever passed". */
    progress: QuizProgress;
};

/**
 * The submission payload: option ids chosen, keyed by question id.
 *
 * Keyed by string because that is what a question id becomes once it is a JSON
 * object key. Questions left blank are simply absent, and the server scores
 * them as wrong rather than as excused.
 */
export type QuizAnswers = Record<string, number[]>;
