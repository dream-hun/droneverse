<?php

namespace App\Actions;

use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\User;
use App\Models\UserChallengeProgress;
use Illuminate\Support\Facades\DB;

class RecordChallengeAttempt
{
    private const MAX_STARS = 3;

    /**
     * Merge a simulator run into the user's per-challenge progress.
     *
     * Client-reported numbers are range-clamped server-side; best score and
     * stars only ever increase; a challenge once completed stays completed.
     * The row is locked inside a transaction so concurrent submissions from
     * the same user cannot produce lost updates.
     *
     * @param  array{score: int, stars: int, completed: bool, code: string}  $attempt
     */
    public function handle(User $user, Challenge $challenge, array $attempt): UserChallengeProgress
    {
        $score = max(0, min($attempt['score'], $challenge->max_score));
        $stars = max(0, min($attempt['stars'], self::MAX_STARS));

        // Ensure the row exists before locking: a SELECT ... FOR UPDATE has no
        // row to lock on a user's first attempt, so two concurrent first
        // attempts would otherwise both insert and one would hit the unique
        // constraint. firstOrCreate() absorbs that race via createOrFirst().
        UserChallengeProgress::query()->firstOrCreate([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
        ]);

        return DB::transaction(function () use ($user, $challenge, $attempt, $score, $stars) {
            $progress = UserChallengeProgress::query()
                ->where('user_id', $user->id)
                ->where('challenge_id', $challenge->id)
                ->lockForUpdate()
                ->firstOrFail();

            $progress->attempts += 1;
            $progress->last_code = $attempt['code'];
            $progress->best_score = max($progress->best_score, $score);
            $progress->stars = max($progress->stars, $stars);

            if ($attempt['completed']) {
                $progress->status = ChallengeStatus::Completed;
                $progress->completed_at ??= now();
            } elseif ($progress->status !== ChallengeStatus::Completed) {
                $progress->status = ChallengeStatus::InProgress;
            }

            $progress->save();

            return $progress;
        });
    }
}
