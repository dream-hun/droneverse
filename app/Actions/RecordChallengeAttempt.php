<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\ChallengeRun;
use App\Models\DroneModel;
use App\Models\User;
use App\Models\UserChallengeProgress;
use App\Queries\FlightLog;
use App\Queries\Leaderboard;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class RecordChallengeAttempt
{
    private const int MAX_STARS = 3;

    public function __construct(
        private Leaderboard $leaderboard,
        private FlightLog $flightLog,
    ) {}

    /**
     * Log a simulator run and merge it into the user's per-challenge progress.
     *
     * Two writes with two different jobs. The progress row is the pilot's
     * standing on the mission: client-reported numbers are range-clamped
     * server-side, best score and stars only ever increase, and a challenge
     * once completed stays completed. The run row is what actually happened
     * on this flight, kept whether it was an improvement or not — a merge
     * that only records improvements cannot answer how long the improvement
     * took.
     *
     * Two more writes follow each of them, but not from here. The leaderboard
     * and the analytics page read rollups rather than aggregating these
     * tables live, and those rollups are maintained by
     * {@see \App\Observers\ChallengeRunObserver} and
     * {@see \App\Observers\UserChallengeProgressObserver} — a total that only
     * stays right while every caller remembers to update it is a total that
     * will eventually be wrong, and this action is not the only thing that
     * writes these rows. Both observers fire inside the transaction below, so
     * a rollup can never commit without the row it was derived from.
     *
     * Both writes happen inside one transaction. A run without its merge
     * would inflate the attempt curve past the attempt counter beside it, and
     * a merge without its run would leave a gap in the curve that no later
     * write can fill, so neither is allowed to land alone. The row is locked
     * for the duration so concurrent submissions from the same pilot cannot
     * produce lost updates.
     *
     * The drone is passed in already resolved rather than read off the
     * progress row here. It is the same airframe the cockpit was rendered
     * with — {@see ResolveMissionDrone} is the one place that
     * decision is made — and taking it as an argument keeps this action
     * honest about the fact that it records a choice it does not get to make.
     *
     * @param  array{score: int, stars: int, completed: bool, objectivesHit: int, objectivesTotal: int, collisions: int, elapsedSeconds: float, landed: bool, timedOut: bool}  $result  as returned by GradeSimulatorRun
     *
     * @throws Throwable
     */
    public function handle(User $user, Challenge $challenge, array $result, string $code, DroneModel $drone): UserChallengeProgress
    {
        $score = max(0, min($result['score'], $challenge->max_score));
        $stars = max(0, min($result['stars'], self::MAX_STARS));

        // `createOrFirst` is safe when two first attempts land together: the
        // unique key elects one creator and the other request selects that
        // same row before both serialize on the lock below. `firstOrCreate`
        // leaks the duplicate-key exception to the losing request.
        UserChallengeProgress::query()->createOrFirst([
            'user_id' => $user->id,
            'challenge_id' => $challenge->id,
        ]);

        $progress = DB::transaction(function () use ($user, $challenge, $result, $code, $score, $stars, $drone): UserChallengeProgress {
            $progress = UserChallengeProgress::query()
                ->where('user_id', $user->id)
                ->where('challenge_id', $challenge->id)
                ->lockForUpdate()
                ->firstOrFail();

            $progress->attempts += 1;
            $progress->last_code = $code;
            $progress->best_score = max($progress->best_score, $score);
            $progress->stars = max($progress->stars, $stars);

            if ($result['completed']) {
                $progress->status = ChallengeStatus::Completed;
                $progress->completed_at ??= now();
            } elseif ($progress->status !== ChallengeStatus::Completed) {
                $progress->status = ChallengeStatus::InProgress;
            }

            $progress->save();

            // The graded numbers, not the pilot's claim about them: this is
            // the same $result the response is built from, and the clamps
            // above are applied here too so a run can never record a score
            // the progress row refused to accept.
            ChallengeRun::query()->create([
                'user_id' => $user->id,
                'challenge_id' => $challenge->id,
                'drone_model_id' => $drone->id,
                'score' => $score,
                'stars' => $stars,
                'completed' => $result['completed'],
                'objectives_hit' => max(0, $result['objectivesHit']),
                'objectives_total' => max(0, $result['objectivesTotal']),
                'collisions' => max(0, $result['collisions']),
                'elapsed_seconds' => max(0.0, $result['elapsedSeconds']),
                'landed' => $result['landed'],
                'timed_out' => $result['timedOut'],
            ]);

            return $progress;
        });

        /*
         * This run may have moved the pilot up this course's board and the
         * overall one, and it is a new point on their own curve. Both read
         * models are told what moved rather than told to forget everything:
         * a run here says nothing about another course's ranking or another
         * pilot's analytics, and retiring those too meant the busiest
         * mission on the site decided how often every other cached slice was
         * rebuilt.
         */
        $this->leaderboard->forgetCourse($challenge->course_id);
        $this->flightLog->forget($user, $challenge);

        return $progress;
    }
}
