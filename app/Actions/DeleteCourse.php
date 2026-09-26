<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Challenge;
use App\Models\Course;
use App\Models\DronePhoto;
use App\Queries\Leaderboard;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Remove a course, and everything anybody ever did in it, for good.
 *
 * The missions, quizzes, progress, runs, attempts, photos and leaderboard
 * totals all cascade at the database level. That is the right place for them
 * to go, and it fires no model events, so the two things events would have
 * done are done here instead.
 *
 * The cached boards are retired, overall and per course: the totals are gone
 * from the table, but a board built from them is still in the cache.
 *
 * The photo files are removed from the photo disk, after the rows and for the
 * reason App\Actions\DeleteUser gives.
 */
final readonly class DeleteCourse
{
    public function __construct(private Leaderboard $leaderboard) {}

    /**
     * @throws Throwable
     */
    public function handle(Course $course): void
    {
        $paths = DronePhoto::query()
            ->whereIn('challenge_id', Challenge::query()->select('id')->where('course_id', $course->id))
            ->pluck('path')
            ->all();

        DB::transaction(fn (): ?bool => $course->delete());

        $this->leaderboard->forgetCourse($course->id);

        if ($paths !== []) {
            DronePhoto::disk()->delete($paths);
        }
    }
}
