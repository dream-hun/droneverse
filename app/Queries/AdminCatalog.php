<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Challenge;
use App\Models\Course;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The course catalogue as authors see it: drafts included, with the counts
 * the admin pages show beside each course.
 */
final readonly class AdminCatalog
{
    /**
     * Every course in author order, with its content counts.
     *
     * @return Collection<int, Course>
     */
    public function courses(): Collection
    {
        return Course::query()
            ->withCount($this->counts())
            ->orderBy('order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Load the same content counts onto a course the route already resolved.
     */
    public function loadCounts(Course $course): Course
    {
        return $course->loadCount($this->counts());
    }

    /**
     * Every mission in the course, drafts included, with its progress count.
     *
     * @return Collection<int, Challenge>
     */
    public function challengesIn(Course $course): Collection
    {
        return $course->challenges()
            ->withCount('progress')
            ->orderBy('id')
            ->get();
    }

    /**
     * Every quiz in the course, drafts included, with its question and
     * progress counts.
     *
     * @return Collection<int, Quiz>
     */
    public function quizzesIn(Course $course): Collection
    {
        return $course->quizzes()->withCount(['questions', 'progress'])->get();
    }

    /**
     * @return array<int|string, mixed>
     */
    private function counts(): array
    {
        return [
            'challenges',
            'challenges as published_challenges_count' => fn (Builder $challenges): Builder => $challenges->where('is_published', true),
            'quizzes',
        ];
    }
}
