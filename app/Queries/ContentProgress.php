<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\Quiz;
use App\Models\User;
use App\Models\UserChallengeProgress;
use App\Models\UserQuizProgress;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * One pilot's progress rows on individual missions and quizzes.
 *
 * The per-item counterpart to {@see PilotProgress}, which reads the per-course
 * rollups. Every list lookup is one query for the whole page rather than one
 * per row, and a guest skips the trip entirely.
 */
final readonly class ContentProgress
{
    /**
     * The pilot's progress row for one mission, if they have flown it.
     */
    public function forChallenge(User $user, Challenge $challenge): ?UserChallengeProgress
    {
        return $user->challengeProgress()
            ->whereBelongsTo($challenge)
            ->first();
    }

    /**
     * The viewer's progress row for one quiz, if they have taken it.
     */
    public function forQuiz(?User $user, Quiz $quiz): ?UserQuizProgress
    {
        return $user?->quizProgress()
            ->whereBelongsTo($quiz)
            ->first();
    }

    /**
     * The viewer's progress on the given challenges, keyed by challenge id.
     *
     * Only the four columns the rows are rendered from. `last_code` is on
     * this table too, and it is a longText holding the pilot's whole editor
     * buffer — up to twenty kilobytes per mission. Selecting `*` read the
     * saved code for every mission in the course, off disk and into a
     * hydrated model, to render a status badge and a star count. The play
     * page is where saved code is actually wanted, and it asks for one row.
     *
     * @param  Collection<int, int>  $challengeIds
     * @return Collection<int, UserChallengeProgress>
     */
    public function byChallenge(?User $user, Collection $challengeIds): Collection
    {
        if (! $user instanceof User || $challengeIds->isEmpty()) {
            return new Collection;
        }

        return $user->challengeProgress()
            ->whereIn('challenge_id', $challengeIds)
            ->get(['challenge_id', 'status', 'best_score', 'stars'])
            ->keyBy(fn (UserChallengeProgress $progress): int => $progress->challenge_id);
    }

    /**
     * The viewer's progress on the given quizzes, keyed by quiz id.
     *
     * `passed_at` is selected as well as the counters because
     * {@see UserQuizProgress::status()} derives the row's state from it, and a
     * row missing the column would read as never passed.
     *
     * @param  Collection<int, int>  $quizIds
     * @return Collection<int, UserQuizProgress>
     */
    public function byQuiz(?User $user, Collection $quizIds): Collection
    {
        if (! $user instanceof User || $quizIds->isEmpty()) {
            return new Collection;
        }

        return $user->quizProgress()
            ->whereIn('quiz_id', $quizIds)
            ->get(['quiz_id', 'best_score', 'attempts', 'passed_at'])
            ->keyBy(fn (UserQuizProgress $progress): int => $progress->quiz_id);
    }

    /**
     * The mission the pilot most recently left in progress, among live ones.
     *
     * Only a published challenge in a published course counts. Whether the
     * pilot's plan still reaches it is the caller's question: a pilot who
     * downgrades keeps the rows from missions they can no longer fly.
     *
     * Three narrow rows rather than three wide ones. A challenge carries its
     * environment, success criteria, starter code and solution code — tens of
     * kilobytes of JSON and source per row — and a progress row carries the
     * pilot's saved editor buffer. The dashboard renders two slugs and a title
     * from this, and needs only the plan columns behind them besides.
     */
    public function latestInProgress(User $user): ?UserChallengeProgress
    {
        return $user->challengeProgress()
            ->select(['id', 'challenge_id'])
            ->with(['challenge' => function (Relation $challenge): void {
                $challenge->select(['id', 'course_id', 'title', 'slug', 'required_plan']);
                $challenge->with(['course' => function (Relation $course): void {
                    $course->select(['id', 'slug', 'required_plan']);
                }]);
            }])
            // A progress row is only created once a pilot starts a mission,
            // so `in_progress` is the only resumable state. An equality here
            // also lets the dashboard use the `(user_id, status, updated_at)`
            // index instead of scanning every completed mission a long-lived
            // account has accumulated and then sorting the survivors.
            ->where('status', ChallengeStatus::InProgress)
            ->whereRelation('challenge', 'is_published', true)
            ->whereRelation('challenge.course', 'is_published', true)
            ->latest('updated_at')
            ->first();
    }
}
