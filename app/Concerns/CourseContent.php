<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\Plan;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Content that hangs off a course, and the rules for reaching it.
 *
 * {@see \App\Models\Challenge} asks whether a pilot can make the drone do
 * something and {@see \App\Models\Quiz} asks whether they understood why, but
 * every route into either one asks the catalogue the same two questions first:
 * does this pair of slugs name content that exists, and does this viewer's
 * plan reach it. Those answers used to be written out once per model, marked
 * in both places as having to stay identical — which is a comment doing a
 * compiler's job. There is one copy now, so "identical" is a property of the
 * code rather than a request to whoever edits it next.
 *
 * What stays on the models is what actually differs: their route key, their
 * relations to their own progress rows, and the things only one of them has —
 * a solution unlock on a mission, a pass mark on a quiz.
 *
 * @property int $course_id
 * @property string|null $required_plan
 * @property bool $is_published
 */
trait CourseContent
{
    /**
     * Whether this content can be reached as part of the given course.
     *
     * Both ends have to be live and the content has to actually belong to the
     * course in the URL, or the pair is indistinguishable from content that
     * does not exist — which is what a caller turns it into, a 404 rather than
     * a 403. Publishing a mission inside an unpublished course does not leak
     * it, and neither does guessing a real slug from the wrong course.
     */
    public function isAvailableIn(Course $course): bool
    {
        return $this->is_published
            && $course->is_published
            && $this->course_id === $course->id;
    }

    /**
     * The plan a pilot needs to reach this content within the given course.
     *
     * Content normally states no tier of its own and takes its course's, which
     * keeps a course and its contents from drifting apart. Setting a value is
     * the deliberate exception: it is how Precision Flight stays a browsable
     * Starter course whose missions are all Pro.
     *
     * The course is passed in rather than read off the relation because every
     * caller already has it from the route, and reaching for `$this->course`
     * here would fire a query per row on a course page.
     */
    public function requiredPlanIn(Course $course): Plan
    {
        return Plan::tryFrom($this->required_plan ?? '') ?? $course->requiredPlan();
    }

    /**
     * Whether this viewer's plan reaches the content. Guests get Starter.
     */
    public function isUnlockedFor(?User $user, Course $course): bool
    {
        $plan = $user?->plan() ?? Plan::Starter;

        return $plan->covers($this->requiredPlanIn($course));
    }

    /**
     * @return BelongsTo<Course, $this>
     */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
