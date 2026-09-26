import { useState } from 'react';
import type { ReactNode } from 'react';
import { FormDialog } from '@/components/form-dialog';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { slugify } from '@/lib/admin-format';
import { store, update } from '@/routes/admin/courses/quizzes';
import type { AdminCourse, AdminQuiz, Option } from '@/types/admin';
import type { PlanValue } from '@/types/auth';

/** Radix refuses an empty item value, so "inherit" needs a name of its own. */
const INHERIT = '__inherit__';

type QuizFormDialogProps = {
    course: AdminCourse;
    quiz?: AdminQuiz;
    plans: Option<PlanValue>[];
    /** Where a new quiz lands in the course; the author can change it. */
    nextOrder?: number;
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
};

/**
 * Create a quiz or edit its details. Questions are written on the quiz's own
 * page, which a new quiz opens onto.
 */
export function QuizFormDialog({
    course,
    quiz,
    plans,
    nextOrder = 0,
    trigger,
    open,
    onOpenChange,
}: QuizFormDialogProps) {
    const editing = quiz !== undefined;
    const [slug, setSlug] = useState(quiz?.slug ?? '');
    const [slugEdited, setSlugEdited] = useState(editing);

    return (
        <FormDialog
            {...(editing
                ? update.form([course.slug, quiz.slug])
                : store.form(course.slug))}
            trigger={trigger}
            open={open}
            onOpenChange={onOpenChange}
            title={
                editing ? `Edit ${quiz.title}` : `New quiz in ${course.title}`
            }
            submitLabel={editing ? 'Save quiz' : 'Create quiz'}
            pendingLabel="Saving…"
            contentClassName="max-h-[90vh] overflow-y-auto sm:max-w-lg"
            transform={(data) => ({
                ...data,
                required_plan:
                    data.required_plan === INHERIT ? null : data.required_plan,
            })}
        >
            {({ errors }) => (
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="quiz-title">Title</Label>
                        <Input
                            id="quiz-title"
                            name="title"
                            defaultValue={quiz?.title}
                            required
                            onChange={(event) => {
                                if (!slugEdited) {
                                    setSlug(slugify(event.target.value));
                                }
                            }}
                        />
                        <InputError message={errors.title} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="quiz-slug">Slug</Label>
                        <Input
                            id="quiz-slug"
                            name="slug"
                            value={slug}
                            required
                            className="font-mono"
                            onChange={(event) => {
                                setSlugEdited(true);
                                setSlug(event.target.value);
                            }}
                        />
                        <InputError message={errors.slug} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="quiz-description">Description</Label>
                        <Textarea
                            id="quiz-description"
                            name="description"
                            defaultValue={quiz?.description}
                            rows={3}
                            required
                        />
                        <InputError message={errors.description} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="quiz-pass">Pass mark (%)</Label>
                            <Input
                                id="quiz-pass"
                                name="pass_percentage"
                                type="number"
                                min={1}
                                max={100}
                                defaultValue={quiz?.passPercentage ?? 70}
                                required
                            />
                            <InputError message={errors.pass_percentage} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="quiz-plan">Tier</Label>
                            <Select
                                name="required_plan"
                                defaultValue={quiz?.requiredPlan ?? INHERIT}
                            >
                                <SelectTrigger
                                    id="quiz-plan"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={INHERIT}>
                                        Course's ({course.requiredPlan})
                                    </SelectItem>
                                    {plans.map((plan) => (
                                        <SelectItem
                                            key={plan.value}
                                            value={plan.value}
                                        >
                                            {plan.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.required_plan} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="quiz-order">Position</Label>
                            <Input
                                id="quiz-order"
                                name="order"
                                type="number"
                                min={0}
                                defaultValue={quiz?.order ?? nextOrder}
                                required
                            />
                            <InputError message={errors.order} />
                        </div>
                    </div>
                    <p className="-mt-2 text-xs text-muted-foreground">
                        Changing the pass mark takes no pass away; it applies to
                        anybody who has not cleared the old one yet.
                    </p>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="quiz-published"
                            name="is_published"
                            value="1"
                            defaultChecked={quiz?.isPublished ?? false}
                        />
                        <Label htmlFor="quiz-published">
                            Published — pilots can see and take it
                        </Label>
                    </div>
                </div>
            )}
        </FormDialog>
    );
}
