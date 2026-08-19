<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\RebuildRollups;
use App\Models\Challenge;
use App\Queries\Leaderboard;
use Throwable;

/**
 * Keeps the leaderboard's rollup honest when the catalogue changes.
 *
 * {@see \App\Models\PilotCourseTotals} sums a pilot's progress over the
 * *published* missions in a course, so unlike every other filter in the read
 * models that one cannot be evaluated at read time — it is already baked into
 * the stored total. Publishing a mission, retiring one, or moving one to a
 * different course therefore changes totals that no run will ever touch
 * again, and without this the board would go on serving them indefinitely
 * rather than for the five minutes a cache TTL costs.
 *
 * Only the columns that can move a total are watched. Retitling a mission or
 * rewriting its briefing changes nothing the board sums, and rebuilding a
 * course on every save would turn a seeder run into a rebuild per row.
 *
 * `courses.is_published` deliberately does not appear here: the read side
 * still joins courses and filters on it, so pulling a whole course takes
 * effect immediately with nothing to recompute.
 *
 * The standing constraint this puts on the rest of the application: because
 * `challenges.is_published` is baked into the stored total and only a model
 * event corrects it, it has to be changed *through a model*. A query-builder
 * mass update — `Challenge::query()->where(...)->update([...])`, or anything
 * on `DB::table('challenges')` — fires no events, and the totals then go on
 * counting missions nobody can fly for good: the cache TTL is no help,
 * because the table is wrong rather than the cache, and the courses page
 * would show a completed count larger than the number of published missions
 * beside it. There is no such caller today. Anything that adds one owes the
 * affected courses a `php artisan rollups:rebuild`.
 */
final readonly class ChallengeObserver
{
    public function __construct(
        private RebuildRollups $rollups,
        private Leaderboard $leaderboard,
    ) {}

    /**
     * `updated` rather than `saved`, so that creating a mission is not an
     * event here at all. A mission nobody has flown yet cannot be in anybody's
     * totals, and seeding a catalogue would otherwise mean a rebuild per row.
     *
     * @throws Throwable
     */
    public function updated(Challenge $challenge): void
    {
        if (! $challenge->wasChanged(['is_published', 'course_id'])) {
            return;
        }

        $this->rebuild($challenge->course_id);

        // A mission that moved between courses leaves a hole in the one it
        // came from, and `getOriginal` is the only place that course is still
        // named by the time the write has landed.
        $formerCourseId = (int) $challenge->getOriginal('course_id');

        if ($formerCourseId !== 0 && $formerCourseId !== $challenge->course_id) {
            $this->rebuild($formerCourseId);
        }
    }

    /**
     * @throws Throwable
     */
    public function deleted(Challenge $challenge): void
    {
        // The progress rows cascade with it, so what is left is a course
        // total summed over missions that are no longer there.
        $this->rebuild($challenge->course_id);
    }

    /**
     * Recompute a course's totals and retire the board built from them.
     *
     * The cache has to go with the table. A rollup that is right behind a
     * cached view of the old one is still a board showing points for a
     * mission nobody can fly, and the TTL is a backstop rather than an
     * answer — five minutes is a long time to serve a standing that has been
     * corrected. This is exact rather than a full flush because the scope the
     * board is keyed by is the course, which is precisely what moved.
     *
     * The analytics cache is deliberately left to its TTL. Retiring a mission
     * moves every pilot's summary and weak spots, and those slices are keyed
     * per pilot — there is no scope that names "everyone" without giving up
     * the narrowing that made the split worth doing.
     *
     * @throws Throwable
     */
    private function rebuild(int $courseId): void
    {
        $this->rollups->courseTotals($courseId);
        $this->leaderboard->forgetCourse($courseId);
    }
}
