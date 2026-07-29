<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\User;
use App\Models\UserChallengeProgress;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RecordChallengeAttempt
{
    private const int MAX_STARS = 3;

    /**
     * Merge a simulator run into the user's per-challenge progress.
     *
     * Client-reported numbers are range-clamped server-side; best score and
     * stars only ever increase; a challenge once completed stays completed.
     * The row is locked inside a transaction so concurrent submissions from
     * the same user cannot produce lost updates.
     *
     * @param  array{score: int, stars: int, completed: bool, code: string}  $attempt
     *
     * @throws Throwable
     */
    public function handle(User $user, Challenge $challenge, array $attempt): UserChallengeProgress
    {
        $score = max(0, min($attempt['score'], $challenge->max_score));
        $stars = max(0, min($attempt['stars'], self::MAX_STARS));

        UserChallengeProgress::query()->firstOrCreate([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
        ]);

        $progress = DB::transaction(function () use ($user, $challenge, $attempt, $score, $stars): UserChallengeProgress {
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

        // This run may have moved the pilot up the board, so no cached view
        // of it can be trusted anymore.
        UserChallengeProgress::forgetBoard();

        return $progress;
    }
}
