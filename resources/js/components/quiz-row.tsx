import { Link } from '@inertiajs/react';
import { CheckCircle2, CircleHelp, Lock, RotateCcw } from 'lucide-react';
import { PILL } from '@/components/marketing/marketing-shell';
import { Skeleton } from '@/components/ui/skeleton';
import { planLabel } from '@/lib/catalog';
import { cn } from '@/lib/utils';
import { pricing } from '@/routes';
import { show as showQuiz } from '@/routes/quizzes';
import type { QuizStatus, QuizSummary } from '@/types/quiz';

const STATUS_ICON: Record<QuizStatus, typeof CircleHelp> = {
    not_started: CircleHelp,
    attempted: RotateCcw,
    passed: CheckCircle2,
};

type QuizRowProps = {
    quiz: QuizSummary;
    courseSlug: string;
};

/**
 * One knowledge check in a course, as a full-width row.
 *
 * The public counterpart of {@link ChallengeRow} and drawn to match it: same
 * panel, same small caps, same rule about what a locked row is allowed to be.
 */
export function QuizRow({ quiz, courseSlug }: QuizRowProps) {
    const Icon = quiz.locked ? Lock : STATUS_ICON[quiz.status];

    const row = (
        <div
            className={cn(
                'flex flex-col gap-4 border border-border bg-white/[0.02] p-6 sm:flex-row sm:items-center sm:justify-between',
                quiz.locked
                    ? 'border-dashed'
                    : 'transition-colors hover:bg-white/[0.04]',
            )}
        >
            <div className="flex min-w-0 items-start gap-5">
                <Icon
                    aria-hidden="true"
                    className={cn(
                        'mt-1 size-4 shrink-0',
                        quiz.passed ? 'text-primary' : 'text-muted-foreground',
                    )}
                />

                <div className="min-w-0">
                    <h3
                        className={cn(
                            'truncate text-lg font-bold tracking-tight uppercase',
                            quiz.locked && 'text-muted-foreground',
                        )}
                    >
                        {quiz.title}
                    </h3>
                    <p className="mt-1 font-mono text-xs tracking-widest text-muted-foreground uppercase">
                        {quiz.questionCount}{' '}
                        {quiz.questionCount === 1 ? 'question' : 'questions'} ·{' '}
                        {quiz.passPercentage}% to pass
                    </p>
                </div>
            </div>

            <div className="flex shrink-0 flex-wrap items-center gap-4 pl-9 sm:pl-0">
                {/*
                 * A best score is only worth showing once there is one.
                 * `attempts` rather than `bestScore > 0` is the test, because
                 * scoring zero on a real attempt is a fact the pilot should
                 * see, not an empty state.
                 */}
                {!quiz.locked && quiz.attempts > 0 && (
                    <span
                        className={cn(
                            PILL,
                            quiz.passed
                                ? 'border-primary text-primary'
                                : 'border-border text-muted-foreground',
                        )}
                    >
                        {quiz.bestScore}%
                    </span>
                )}

                {quiz.locked ? (
                    <>
                        <span
                            className={cn(PILL, 'border-primary text-primary')}
                        >
                            <Lock aria-hidden className="size-3" />
                            {planLabel(quiz.requiredPlan)}
                        </span>
                        <Link
                            href={pricing()}
                            className="font-mono text-xs tracking-widest text-muted-foreground uppercase transition-colors hover:text-primary"
                        >
                            See plans →
                        </Link>
                    </>
                ) : (
                    <span className="font-mono text-xs tracking-widest text-primary uppercase">
                        Take it →
                    </span>
                )}
            </div>
        </div>
    );

    // A locked quiz is shown in full but is not a link, for the same reason a
    // locked mission is not: the route would turn the pilot away, and a link
    // that 403s reads as a bug rather than a paywall.
    if (quiz.locked) {
        return row;
    }

    return (
        <Link
            href={showQuiz([courseSlug, quiz.slug])}
            className="block outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
        >
            {row}
        </Link>
    );
}

/** Layout-stable placeholder matching a single `QuizRow`. */
export function QuizRowSkeleton() {
    return (
        <div
            aria-hidden="true"
            className="flex items-center justify-between gap-4 border border-border bg-white/[0.02] p-6"
        >
            <div className="flex items-center gap-5">
                <Skeleton className="size-4 shrink-0" />
                <div className="space-y-2">
                    <Skeleton className="h-5 w-40" />
                    <Skeleton className="h-3 w-28" />
                </div>
            </div>
            <Skeleton className="h-4 w-16 shrink-0" />
        </div>
    );
}
