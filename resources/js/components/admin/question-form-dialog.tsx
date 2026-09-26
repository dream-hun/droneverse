import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { FormDialog } from '@/components/form-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { store, update } from '@/routes/admin/courses/quizzes/questions';
import type { AdminCourse, AdminQuiz, AdminQuizQuestion } from '@/types/admin';

/** The same bounds SaveQuizQuestionRequest holds the list to. */
const MIN_ANSWERS = 2;
const MAX_ANSWERS = 8;

type AnswerRow = {
    /** React's key; stable while rows are added and removed around it. */
    key: string;
    /** The saved option's id, so an edit updates it rather than replacing it. */
    id: number | null;
    label: string;
    isCorrect: boolean;
};

function blankRow(): AnswerRow {
    return { key: crypto.randomUUID(), id: null, label: '', isCorrect: false };
}

function rowsFor(question?: AdminQuizQuestion): AnswerRow[] {
    if (!question) {
        return [blankRow(), blankRow()];
    }

    return question.options.map((option) => ({
        key: `option-${option.id}`,
        id: option.id,
        label: option.label,
        isCorrect: option.isCorrect,
    }));
}

type QuestionFormDialogProps = {
    course: AdminCourse;
    quiz: AdminQuiz;
    question?: AdminQuizQuestion;
    nextOrder?: number;
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
};

/**
 * Write a question and its answers, marking which are correct.
 *
 * Whether pilots see radio buttons or checkboxes is not a separate setting:
 * it follows from how many answers are ticked correct, which is the only way
 * the two can never disagree. The line under the answers says which it will
 * be as the author ticks.
 *
 * Answers keep the id they were saved with, so fixing a typo in one updates it
 * in place — a pilot with the quiz open still has an answer that counts.
 */
export function QuestionFormDialog({
    course,
    quiz,
    question,
    nextOrder = 0,
    trigger,
    open,
    onOpenChange,
}: QuestionFormDialogProps) {
    const editing = question !== undefined;
    const [rows, setRows] = useState<AnswerRow[]>(() => rowsFor(question));

    const correct = rows.filter((row) => row.isCorrect).length;

    function change(key: string, patch: Partial<AnswerRow>) {
        setRows((current) =>
            current.map((row) =>
                row.key === key ? { ...row, ...patch } : row,
            ),
        );
    }

    return (
        <FormDialog
            {...(editing
                ? update.form([course.slug, quiz.slug, question.uuid])
                : store.form([course.slug, quiz.slug]))}
            trigger={trigger}
            open={open}
            onOpenChange={(next) => {
                // A fresh set of blanks each time the new-question form opens.
                if (!next && !editing) {
                    setRows(rowsFor());
                }

                onOpenChange?.(next);
            }}
            title={editing ? 'Edit question' : `New question for ${quiz.title}`}
            submitLabel={editing ? 'Save question' : 'Add question'}
            pendingLabel="Saving…"
            contentClassName="max-h-[90vh] overflow-y-auto sm:max-w-xl"
        >
            {({ errors }) => {
                const answerError =
                    errors.options ??
                    Object.entries(errors).find(([field]) =>
                        field.startsWith('options.'),
                    )?.[1];

                return (
                    <div className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="question-prompt">Question</Label>
                            <Textarea
                                id="question-prompt"
                                name="prompt"
                                defaultValue={question?.prompt}
                                rows={3}
                                required
                            />
                            <InputError message={errors.prompt} />
                        </div>

                        <fieldset className="grid gap-2">
                            <legend className="mb-1 text-sm font-medium">
                                Answers — tick every correct one
                            </legend>
                            {rows.map((row, index) => (
                                <div
                                    key={row.key}
                                    className="flex items-center gap-2"
                                >
                                    {row.id !== null && (
                                        <input
                                            type="hidden"
                                            name={`options[${index}][id]`}
                                            value={row.id}
                                        />
                                    )}
                                    <Checkbox
                                        id={`answer-${row.key}`}
                                        name={`options[${index}][is_correct]`}
                                        value="1"
                                        checked={row.isCorrect}
                                        onCheckedChange={(checked) =>
                                            change(row.key, {
                                                isCorrect: checked === true,
                                            })
                                        }
                                        aria-label={`Answer ${index + 1} is correct`}
                                    />
                                    <Input
                                        id={`answer-label-${row.key}`}
                                        name={`options[${index}][label]`}
                                        value={row.label}
                                        onChange={(event) =>
                                            change(row.key, {
                                                label: event.target.value,
                                            })
                                        }
                                        placeholder={`Answer ${index + 1}`}
                                        aria-label={`Answer ${index + 1}`}
                                        required
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        disabled={rows.length <= MIN_ANSWERS}
                                        onClick={() =>
                                            setRows((current) =>
                                                current.filter(
                                                    (other) =>
                                                        other.key !== row.key,
                                                ),
                                            )
                                        }
                                    >
                                        <X aria-hidden="true" />
                                        <span className="sr-only">
                                            Remove answer {index + 1}
                                        </span>
                                    </Button>
                                </div>
                            ))}

                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <p
                                    className="text-xs text-muted-foreground"
                                    aria-live="polite"
                                >
                                    {correct === 0
                                        ? 'No answer marked correct yet.'
                                        : correct === 1
                                          ? 'One correct answer — pilots pick one.'
                                          : `${correct} correct answers — pilots must tick all of them.`}
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={rows.length >= MAX_ANSWERS}
                                    onClick={() =>
                                        setRows((current) => [
                                            ...current,
                                            blankRow(),
                                        ])
                                    }
                                >
                                    <Plus />
                                    Add answer
                                </Button>
                            </div>
                            <InputError message={answerError} />
                        </fieldset>

                        <div className="grid gap-2">
                            <Label htmlFor="question-explanation">
                                Explanation
                            </Label>
                            <Textarea
                                id="question-explanation"
                                name="explanation"
                                defaultValue={question?.explanation ?? ''}
                                rows={3}
                            />
                            <p className="text-xs text-muted-foreground">
                                Optional. Shown only after a pilot submits, so
                                it can give the answer away.
                            </p>
                            <InputError message={errors.explanation} />
                        </div>

                        <div className="grid gap-2 sm:w-32">
                            <Label htmlFor="question-order">Position</Label>
                            <Input
                                id="question-order"
                                name="order"
                                type="number"
                                min={0}
                                defaultValue={question?.order ?? nextOrder}
                                required
                            />
                            <InputError message={errors.order} />
                        </div>
                    </div>
                );
            }}
        </FormDialog>
    );
}
