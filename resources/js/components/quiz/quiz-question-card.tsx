import { Check, X } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { QuizQuestionPrompt, QuizQuestionResult } from '@/types/quiz';

type QuizQuestionCardProps = {
    question: QuizQuestionPrompt;
    /** Zero-based; displayed as the question's position in the quiz. */
    index: number;
    /** Option ids currently chosen for this question. */
    selected: number[];
    onChange: (optionIds: number[]) => void;
    /** Present once the submission has been graded; locks the inputs. */
    result?: QuizQuestionResult;
};

/**
 * How one option should be marked up once the quiz has been graded.
 *
 * The four states are deliberately distinct. A pilot reviewing a failed quiz
 * needs to see both what they picked and what they should have picked, and
 * collapsing "missed" into plain would hide the right answer on exactly the
 * questions where it matters most.
 */
function optionState(
    optionId: number,
    result: QuizQuestionResult | undefined,
): 'neutral' | 'correct' | 'wrong' | 'missed' {
    if (!result) {
        return 'neutral';
    }

    const chosen = result.selectedOptionIds.includes(optionId);
    const isCorrect = result.correctOptionIds.includes(optionId);

    if (chosen && isCorrect) {
        return 'correct';
    }

    if (chosen) {
        return 'wrong';
    }

    return isCorrect ? 'missed' : 'neutral';
}

const OPTION_STATE_CLASS: Record<ReturnType<typeof optionState>, string> = {
    neutral: 'border-input',
    correct: 'border-emerald-500/60 bg-emerald-500/10',
    wrong: 'border-destructive/60 bg-destructive/10',
    missed: 'border-emerald-500/40 border-dashed',
};

/** One question, its options, and — once graded — how it went. */
export function QuizQuestionCard({
    question,
    index,
    selected,
    onChange,
    result,
}: QuizQuestionCardProps) {
    const graded = result !== undefined;

    /*
     * Native inputs, visually hidden behind a styled label rather than
     * replaced by a custom widget. Radio groups get arrow-key navigation,
     * checkbox groups get space to toggle, and both announce their checked
     * state to a screen reader — all for free, and none of it worth
     * reimplementing to change how a circle looks.
     */
    const inputType = question.allowsMultiple ? 'checkbox' : 'radio';

    function toggle(optionId: number, checked: boolean) {
        if (!question.allowsMultiple) {
            onChange(checked ? [optionId] : []);

            return;
        }

        onChange(
            checked
                ? [...selected, optionId]
                : selected.filter((id) => id !== optionId),
        );
    }

    return (
        <Card
            className={cn(
                graded &&
                    (result.correct
                        ? 'border-emerald-500/40'
                        : 'border-destructive/40'),
            )}
        >
            <CardHeader className="space-y-2">
                <div className="flex items-start justify-between gap-3">
                    <CardTitle className="text-base leading-relaxed font-medium">
                        <span className="text-muted-foreground">
                            {index + 1}.
                        </span>{' '}
                        {question.prompt}
                    </CardTitle>
                    {graded && (
                        <Badge
                            variant="outline"
                            className={cn(
                                'shrink-0 gap-1',
                                result.correct
                                    ? 'text-emerald-600 dark:text-emerald-400'
                                    : 'text-destructive',
                            )}
                        >
                            {result.correct ? (
                                <Check aria-hidden="true" className="size-3" />
                            ) : (
                                <X aria-hidden="true" className="size-3" />
                            )}
                            {result.correct ? 'Correct' : 'Incorrect'}
                        </Badge>
                    )}
                </div>
                {question.allowsMultiple && (
                    <p className="text-xs text-muted-foreground">
                        Select all that apply.
                    </p>
                )}
            </CardHeader>

            <CardContent className="space-y-2">
                <div
                    role="group"
                    aria-label={`Answers for question ${index + 1}`}
                    className="space-y-2"
                >
                    {question.options.map((option) => {
                        const state = optionState(option.id, result);
                        const checked = selected.includes(option.id);

                        return (
                            <label
                                key={option.id}
                                className={cn(
                                    'flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm transition-colors',
                                    'has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50',
                                    OPTION_STATE_CLASS[state],
                                    !graded &&
                                        'hover:bg-accent/50 has-[:checked]:border-primary has-[:checked]:bg-primary/5',
                                    graded && 'cursor-default',
                                )}
                            >
                                <input
                                    type={inputType}
                                    name={`question-${question.id}`}
                                    value={option.id}
                                    checked={checked}
                                    disabled={graded}
                                    onChange={(event) =>
                                        toggle(option.id, event.target.checked)
                                    }
                                    className={cn(
                                        'mt-0.5 size-4 shrink-0 accent-primary',
                                        graded && 'opacity-70',
                                    )}
                                />
                                <span className="leading-relaxed">
                                    {option.label}
                                </span>
                            </label>
                        );
                    })}
                </div>

                {graded && result.explanation && (
                    <p className="rounded-lg bg-muted/50 p-3 text-sm text-muted-foreground">
                        {result.explanation}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}
