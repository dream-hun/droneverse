import { useState } from 'react';
import type { ReactNode } from 'react';
import { DIFFICULTY_SUGGESTIONS } from '@/components/admin/course-form-dialog';
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
import { store, update } from '@/routes/admin/courses/challenges';
import type { AdminChallenge, AdminCourse, Option } from '@/types/admin';
import type { PlanValue } from '@/types/auth';

/** Radix refuses an empty item value, so "inherit" needs a name of its own. */
const INHERIT = '__inherit__';

/*
 * What a new mission starts from: the smallest world the simulator will fly
 * and the smallest set of rules the grader will score — take off, hover, land.
 * The same shape database/factories/ChallengeFactory.php builds, so a mission
 * saved untouched is one the simulator is known to accept.
 */
const STARTER_CODE = `async function main(drone) {
    await drone.takeoff();
    await drone.hover(2);
    await drone.land();
}
`;

const ENVIRONMENT = JSON.stringify(
    {
        start: { x: 0, y: 0, z: 0, yaw: 0 },
        bounds: { width: 20, depth: 20, height: 10 },
        obstacles: [],
        gates: [],
        waypoints: [],
        goal: { x: 0, z: 0, radius: 1.2 },
    },
    null,
    4,
);

const SUCCESS_CRITERIA = JSON.stringify(
    {
        type: 'waypoints',
        waypoints: [],
        min_altitude: 1,
        avoid_collisions: false,
        max_time_seconds: 30,
        landing_required: true,
    },
    null,
    4,
);

type ChallengeFormDialogProps = {
    course: AdminCourse;
    challenge?: AdminChallenge;
    plans: Option<PlanValue>[];
    /** Where a new mission lands in the course; the author can change it. */
    nextOrder?: number;
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
};

function CodeField({
    id,
    name,
    label,
    hint,
    defaultValue,
    error,
    rows = 10,
    required = true,
}: {
    id: string;
    name: string;
    label: string;
    hint?: string;
    defaultValue: string;
    error?: string;
    rows?: number;
    required?: boolean;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Textarea
                id={id}
                name={name}
                defaultValue={defaultValue}
                rows={rows}
                required={required}
                spellCheck={false}
                className="field-sizing-fixed font-mono text-[11px] leading-relaxed md:text-[11px]"
            />
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

/**
 * Author a mission, or revise one: its briefing, its code, its world and the
 * rules it is graded by.
 *
 * The world and the rules are edited as JSON text and checked by the server
 * against the shape the simulator flies to, with anything missing reported
 * under the field it belongs in. Changing the rules does not re-grade anybody;
 * a pilot's best stands and the new rules apply from their next run.
 */
export function ChallengeFormDialog({
    course,
    challenge,
    plans,
    nextOrder = 0,
    trigger,
    open,
    onOpenChange,
}: ChallengeFormDialogProps) {
    const editing = challenge !== undefined;
    const [slug, setSlug] = useState(challenge?.slug ?? '');
    const [slugEdited, setSlugEdited] = useState(editing);

    return (
        <FormDialog
            {...(editing
                ? update.form([course.slug, challenge.slug])
                : store.form(course.slug))}
            trigger={trigger}
            open={open}
            onOpenChange={onOpenChange}
            title={
                editing
                    ? `Edit ${challenge.title}`
                    : `New mission in ${course.title}`
            }
            submitLabel={editing ? 'Save mission' : 'Create mission'}
            pendingLabel="Saving…"
            contentClassName="max-h-[90vh] overflow-y-auto sm:max-w-3xl"
            transform={(data) => ({
                ...data,
                required_plan:
                    data.required_plan === INHERIT ? null : data.required_plan,
            })}
        >
            {({ errors }) => (
                <div className="grid gap-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="challenge-title">Title</Label>
                            <Input
                                id="challenge-title"
                                name="title"
                                defaultValue={challenge?.title}
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
                            <Label htmlFor="challenge-slug">Slug</Label>
                            <Input
                                id="challenge-slug"
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
                    </div>

                    <div className="grid gap-4 sm:grid-cols-4">
                        <div className="grid gap-2">
                            <Label htmlFor="challenge-difficulty">
                                Difficulty
                            </Label>
                            <Input
                                id="challenge-difficulty"
                                name="difficulty"
                                list="challenge-difficulties"
                                defaultValue={
                                    challenge?.difficulty ?? course.difficulty
                                }
                                required
                            />
                            <datalist id="challenge-difficulties">
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
                            <Label htmlFor="challenge-plan">Tier</Label>
                            <Select
                                name="required_plan"
                                defaultValue={
                                    challenge?.requiredPlan ?? INHERIT
                                }
                            >
                                <SelectTrigger
                                    id="challenge-plan"
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
                            <Label htmlFor="challenge-order">Position</Label>
                            <Input
                                id="challenge-order"
                                name="order"
                                type="number"
                                min={0}
                                defaultValue={challenge?.order ?? nextOrder}
                                required
                            />
                            <InputError message={errors.order} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="challenge-max-score">
                                Max score
                            </Label>
                            <Input
                                id="challenge-max-score"
                                name="max_score"
                                type="number"
                                min={1}
                                defaultValue={challenge?.maxScore ?? 100}
                                required
                            />
                            <InputError message={errors.max_score} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="challenge-briefing">Briefing</Label>
                        <Textarea
                            id="challenge-briefing"
                            name="briefing"
                            defaultValue={challenge?.briefing}
                            rows={5}
                            required
                        />
                        <p className="text-xs text-muted-foreground">
                            Markdown. Inline code in backticks renders as code.
                        </p>
                        <InputError message={errors.briefing} />
                    </div>

                    <div className="grid gap-4 lg:grid-cols-2">
                        <CodeField
                            id="challenge-starter"
                            name="starter_code"
                            label="Starter code"
                            hint="What the editor opens with. It must define async function main(drone)."
                            defaultValue={
                                challenge?.starterCode ?? STARTER_CODE
                            }
                            error={errors.starter_code}
                        />
                        <CodeField
                            id="challenge-solution"
                            name="solution_code"
                            label="Reference solution"
                            hint="Optional. Unlocks for a pilot who clears the mission or has made three attempts."
                            defaultValue={challenge?.solutionCode ?? ''}
                            error={errors.solution_code}
                            required={false}
                        />
                    </div>

                    <div className="grid gap-4 lg:grid-cols-2">
                        <CodeField
                            id="challenge-environment"
                            name="environment"
                            label="World (JSON)"
                            hint="Needs start, bounds, goal, gates and waypoints; obstacles, props, wind and carwash are optional."
                            defaultValue={challenge?.environment ?? ENVIRONMENT}
                            error={errors.environment}
                            rows={14}
                        />
                        <CodeField
                            id="challenge-criteria"
                            name="success_criteria"
                            label="Grading rules (JSON)"
                            hint="Needs type, waypoints, avoid_collisions, max_time_seconds and landing_required."
                            defaultValue={
                                challenge?.successCriteria ?? SUCCESS_CRITERIA
                            }
                            error={errors.success_criteria}
                            rows={14}
                        />
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="challenge-published"
                            name="is_published"
                            value="1"
                            defaultChecked={challenge?.isPublished ?? false}
                        />
                        <Label htmlFor="challenge-published">
                            Published — pilots can see and fly it
                        </Label>
                    </div>
                </div>
            )}
        </FormDialog>
    );
}
