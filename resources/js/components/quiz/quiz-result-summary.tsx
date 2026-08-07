import { Award, RotateCcw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { cn } from '@/lib/utils';
import type { QuizResult } from '@/types/quiz';

type QuizResultSummaryProps = {
    result: QuizResult;
    passPercentage: number;
    onRetake: () => void;
};

/** The scoreboard shown once a submission has been graded. */
export function QuizResultSummary({
    result,
    passPercentage,
    onRetake,
}: QuizResultSummaryProps) {
    /*
     * Two different facts, and the distinction is the whole reason this panel
     * takes the merged progress as well as the attempt. `result.passed` is
     * whether *this* submission cleared the bar; `result.progress.passed` is
     * whether the pilot has ever cleared it. A pilot retaking a quiz they
     * already passed, for a better score, must not be told they have just
     * failed it.
     */
    const passedThisTime = result.passed;
    const passedEver = result.progress.passed;

    return (
        <Card
            className={cn(
                passedThisTime
                    ? 'border-emerald-500/40 bg-emerald-500/5'
                    : 'border-destructive/40 bg-destructive/5',
            )}
            role="status"
            aria-live="polite"
        >
            <CardContent className="space-y-4 pt-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div className="space-y-1">
                        <p className="text-2xl font-semibold">
                            {passedThisTime ? 'Passed' : 'Not quite'}
                            <span className="ml-2 text-muted-foreground">
                                {result.score}%
                            </span>
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {result.correctCount} of {result.questionCount}{' '}
                            correct — {passPercentage}% needed to pass.
                        </p>
                    </div>

                    <Button variant="outline" onClick={onRetake}>
                        <RotateCcw
                            aria-hidden="true"
                            data-icon="inline-start"
                        />
                        Try again
                    </Button>
                </div>

                <Progress
                    value={result.score}
                    aria-label={`Scored ${result.score} percent`}
                />

                {/*
                 * Only shown when the two facts disagree — that is, when this
                 * attempt fell short but an earlier one did not. Saying it on
                 * every failed attempt would be noise; saying it here is the
                 * difference between "you lost your pass" and "you didn't".
                 */}
                {!passedThisTime && passedEver && (
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Award aria-hidden="true" className="size-4 shrink-0" />
                        You already passed this quiz — your best score of{' '}
                        {result.progress.bestScore}% still stands.
                    </p>
                )}
            </CardContent>
        </Card>
    );
}
