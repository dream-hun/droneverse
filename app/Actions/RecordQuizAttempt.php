<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Models\UserQuizProgress;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class RecordQuizAttempt
{
    /**
     * Log a graded submission and merge it into the pilot's quiz progress.
     *
     * The same two writes with the same two jobs as {@see RecordChallengeAttempt},
     * and for the same reasons. The progress row is the pilot's standing on
     * the quiz: the best score only ever rises, and a quiz once passed stays
     * passed. The attempt row is what actually happened on this submission,
     * kept whether it was an improvement or not — a merge that only records
     * improvements cannot answer how many tries a quiz takes.
     *
     * Both happen inside one transaction. An attempt without its merge would
     * inflate the history past the attempt counter beside it, and a merge
     * without its attempt would leave a gap in the history that no later
     * write can fill, so neither is allowed to land alone. The row is locked
     * for the duration so two submissions from the same pilot cannot produce
     * a lost update — which matters more here than it looks: retakes are
     * unlimited and a double-submitted form is the ordinary way two graded
     * results arrive at once.
     *
     * `passed_at` is set once and never cleared. Retaking a quiz after
     * passing it — to review the questions, or to try for full marks — must
     * not be able to cost the pilot the pass they already hold.
     *
     * Nothing is invalidated afterwards, unlike the mission path. The
     * leaderboard ranks flying and `Queries\FlightLog` reads runs; neither
     * reads a quiz, so neither has a cached view that this write could make
     * stale. When quiz results start appearing on either, the invalidation
     * belongs here.
     *
     * @param  array{score: int, correctCount: int, questionCount: int, passed: bool, questions: array<int, mixed>}  $result  as returned by GradeQuizSubmission
     *
     * @throws Throwable
     */
    public function handle(User $user, Quiz $quiz, array $result): UserQuizProgress
    {
        // Clamped here as well as in the grader. This is the boundary the
        // database sees, and `best_score` is an unsigned tiny int — a score
        // outside 0..100 is a bug somewhere upstream, and it should not be
        // able to become a truncated row or a failed insert.
        $score = max(0, min($result['score'], 100));

        UserQuizProgress::query()->firstOrCreate([
            'user_id' => $user->id,
            'quiz_id' => $quiz->id,
        ]);

        return DB::transaction(function () use ($user, $quiz, $result, $score): UserQuizProgress {
            $progress = UserQuizProgress::query()
                ->where('user_id', $user->id)
                ->where('quiz_id', $quiz->id)
                ->lockForUpdate()
                ->firstOrFail();

            $progress->attempts += 1;
            $progress->best_score = max($progress->best_score, $score);

            if ($result['passed']) {
                $progress->passed_at ??= now();
            }

            $progress->save();

            // The graded numbers, not the pilot's claim about them: the same
            // $result the response is built from, with the same clamp applied
            // so an attempt can never record a score the progress row refused.
            QuizAttempt::query()->create([
                'user_id' => $user->id,
                'quiz_id' => $quiz->id,
                'score' => $score,
                'correct_count' => max(0, $result['correctCount']),
                'question_count' => max(0, $result['questionCount']),
                'passed' => $result['passed'],
            ]);

            return $progress;
        });
    }
}
