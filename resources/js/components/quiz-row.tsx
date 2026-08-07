import { Link } from '@inertiajs/react';
import { CheckCircle2, CircleHelp, Lock, RotateCcw } from 'lucide-react';
import { PlanLockBadge, PlanUpgradeHint } from '@/components/plan-lock-badge';
import { Badge } from '@/components/ui/badge';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
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

/** One knowledge check in a course, as a full-width row. */
export function QuizRow({ quiz, courseSlug }: QuizRowProps) {
    const Icon = quiz.locked ? Lock : STATUS_ICON[quiz.status];

    const card = (
        <Card
            className={
                quiz.locked
                    ? 'border-dashed'
                    : 'transition-shadow hover:shadow-md'
            }
        >
            <CardHeader className="flex-row items-center justify-between gap-3 space-y-0">
                <div className="flex min-w-0 items-center gap-3">
                    <Icon
                        aria-hidden="true"
                        className={cn(
                            'size-5 shrink-0',
                            quiz.passed
                                ? 'text-emerald-600 dark:text-emerald-400'
                                : 'text-muted-foreground',
                        )}
                    />
                    <div className="min-w-0">
                        <CardTitle
                            className={cn(
                                'text-base',
                                quiz.locked && 'text-muted-foreground',
                            )}
                        >
                            {quiz.title}
                        </CardTitle>
                        {quiz.locked ? (
                            <PlanUpgradeHint plan={quiz.requiredPlan} />
                        ) : (
                            <p className="text-xs text-muted-foreground">
                                {quiz.questionCount} question
                                {quiz.questionCount === 1 ? '' : 's'} ·{' '}
                                {quiz.passPercentage}% to pass
                            </p>
                        )}
                    </div>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    {quiz.locked && (
                        <PlanLockBadge asLink plan={quiz.requiredPlan} />
                    )}
                    {/*
                     * A best score is only worth showing once there is one.
                     * `attempts` rather than `bestScore > 0` is the test,
                     * because scoring zero on a real attempt is a fact the
                     * pilot should see, not an empty state.
                     */}
                    {!quiz.locked && quiz.attempts > 0 && (
                        <Badge variant={quiz.passed ? 'default' : 'outline'}>
                            {quiz.bestScore}%
                        </Badge>
                    )}
                </div>
            </CardHeader>
        </Card>
    );

    // A locked quiz is shown in full but is not a link, for the same reason a
    // locked mission is not: the route would turn the pilot away, and a link
    // that 403s reads as a bug rather than a paywall.
    if (quiz.locked) {
        return card;
    }

    return (
        <Link
            href={showQuiz([courseSlug, quiz.slug])}
            className="block rounded-xl outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
        >
            {card}
        </Link>
    );
}

/** Layout-stable placeholder matching a single `QuizRow`. */
export function QuizRowSkeleton() {
    return (
        <Card aria-hidden="true">
            <CardHeader className="flex-row items-center justify-between gap-3 space-y-0">
                <div className="flex min-w-0 items-center gap-3">
                    <Skeleton className="size-5 shrink-0 rounded-full" />
                    <div className="space-y-1.5">
                        <Skeleton className="h-4 w-40" />
                        <Skeleton className="h-3 w-28" />
                    </div>
                </div>
                <Skeleton className="h-5 w-12 shrink-0" />
            </CardHeader>
        </Card>
    );
}
