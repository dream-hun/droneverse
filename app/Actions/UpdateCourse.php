<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Course;
use App\Queries\Leaderboard;

/**
 * Edit a course's catalogue entry.
 *
 * Publishing or pulling a course needs no recompute — the leaderboard joins
 * courses and filters on the flag at read time, which is why
 * App\Observers\ChallengeObserver leaves it alone — but the board that read
 * the old answer is cached, so it is retired here rather than left to serve
 * points for a course nobody can open until its TTL runs out.
 *
 * A changed slug moves the course's public URL, and its written guide with it:
 * config/course-docs.php is keyed by slug, so a course renamed here loses its
 * docs page until that key is renamed to match.
 */
final readonly class UpdateCourse
{
    public function __construct(private Leaderboard $leaderboard) {}

    /**
     * @param  array{title: string, slug: string, description: string, difficulty: string, required_plan: string, order: int, is_published: bool}  $attributes
     */
    public function handle(Course $course, array $attributes): Course
    {
        $course->update($attributes);

        if ($course->wasChanged('is_published')) {
            $this->leaderboard->forgetCourse($course->id);
        }

        return $course;
    }
}
