<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Challenge;
use App\Models\Course;

/**
 * Add a mission to a course.
 *
 * Nothing to recompute. App\Observers\ChallengeObserver ignores creation on
 * purpose — a mission nobody has flown cannot be in anybody's totals — and a
 * course's totals count only missions that have been flown.
 */
final readonly class CreateChallenge
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Course $course, array $attributes): Challenge
    {
        return $course->challenges()->create($attributes);
    }
}
