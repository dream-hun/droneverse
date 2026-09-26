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
import { store, update } from '@/routes/admin/courses';
import type { AdminCourse, Option } from '@/types/admin';
import type { PlanValue } from '@/types/auth';

/** The difficulties the seeded catalogue uses; the field accepts any label. */
export const DIFFICULTY_SUGGESTIONS = ['beginner', 'intermediate', 'advanced'];

type CourseFormDialogProps = {
    course?: AdminCourse;
    plans: Option<PlanValue>[];
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
};

/**
 * Create a course or edit its catalogue entry.
 *
 * On a new course the slug follows the title until it is typed into; on an
 * existing one it is left alone, because it is the course's public URL and
 * the key its written guide is filed under.
 */
export function CourseFormDialog({
    course,
    plans,
    trigger,
    open,
    onOpenChange,
}: CourseFormDialogProps) {
    const editing = course !== undefined;
    const [slug, setSlug] = useState(course?.slug ?? '');
    const [slugEdited, setSlugEdited] = useState(editing);

    return (
        <FormDialog
            {...(editing ? update.form(course.slug) : store.form())}
            key={course?.slug ?? 'new'}
            trigger={trigger}
            open={open}
            onOpenChange={onOpenChange}
            title={editing ? `Edit ${course.title}` : 'New course'}
            submitLabel={editing ? 'Save course' : 'Create course'}
            pendingLabel="Saving…"
            contentClassName="max-h-[90vh] overflow-y-auto sm:max-w-lg"
        >
            {({ errors }) => (
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="course-title">Title</Label>
                        <Input
                            id="course-title"
                            name="title"
                            defaultValue={course?.title}
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
                        <Label htmlFor="course-slug">Slug</Label>
                        <Input
                            id="course-slug"
                            name="slug"
                            value={slug}
                            required
                            className="font-mono"
                            onChange={(event) => {
                                setSlugEdited(true);
                                setSlug(event.target.value);
                            }}
                        />
                        {editing && (
                            <p className="text-xs text-muted-foreground">
                                Changing it moves the course's URL, and its
                                written guide until config/course-docs.php is
                                renamed to match.
                            </p>
                        )}
                        <InputError message={errors.slug} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="course-description">Description</Label>
                        <Textarea
                            id="course-description"
                            name="description"
                            defaultValue={course?.description}
                            rows={3}
                            required
                        />
                        <InputError message={errors.description} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="course-difficulty">
                                Difficulty
                            </Label>
                            <Input
                                id="course-difficulty"
                                name="difficulty"
                                list="course-difficulties"
                                defaultValue={course?.difficulty ?? 'beginner'}
                                required
                            />
                            <datalist id="course-difficulties">
                                {DIFFICULTY_SUGGESTIONS.map((difficulty) => (
                                    <option
                                        key={difficulty}
                                        value={difficulty}
                                    />
                                ))}
                            </datalist>
                            <InputError message={errors.difficulty} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="course-plan">Tier</Label>
                            <Select
                                name="required_plan"
                                defaultValue={course?.requiredPlan ?? 'starter'}
                            >
                                <SelectTrigger
                                    id="course-plan"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
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
                            <Label htmlFor="course-order">Position</Label>
                            <Input
                                id="course-order"
                                name="order"
                                type="number"
                                min={0}
                                defaultValue={course?.order ?? 0}
                                required
                            />
                            <InputError message={errors.order} />
                        </div>
                    </div>
                    <p className="-mt-2 text-xs text-muted-foreground">
                        The tier is what the course's missions inherit unless a
                        mission names its own. The course page itself stays open
                        to everyone.
                    </p>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="course-published"
                            name="is_published"
                            value="1"
                            defaultChecked={course?.isPublished ?? false}
                        />
                        <Label htmlFor="course-published">
                            Published — listed in the catalogue
                        </Label>
                    </div>
                </div>
            )}
        </FormDialog>
    );
}
