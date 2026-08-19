<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ChallengeRun;
use App\Models\PilotMissionStats;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fold a graded run into the pilot's running totals for that mission.
 *
 * The write half of {@see PilotMissionStats}. Analytics used to
 * answer every question by aggregating a pilot's entire run history on each
 * cache miss, so the cost of reading their summary rose with how much they
 * had flown — which is backwards, because the pilots who fly most are the
 * ones the page is for. This keeps the answer current as the runs land
 * instead, which is O(1) per run and bounded forever.
 *
 * Incremental rather than recomputed, the opposite choice from
 * {@see RollUpCourseTotals} and for the opposite reason: recomputing this row
 * would mean re-reading the very history the rollup exists to stop reading.
 * The price of that choice is that it *can* drift, so it is paid for twice —
 * by {@see RebuildRollups}, which derives every column from the runs again,
 * and by the test that asserts the two paths agree.
 *
 * Reached through {@see \App\Observers\ChallengeRunObserver} rather than
 * called at the one place runs are recorded today. A rollup that a caller has
 * to remember to update is a rollup that is wrong the first time someone
 * writes a run without knowing about it, and the tests that build run
 * histories with factories are already that caller.
 */
final readonly class RollUpChallengeRun
{
    /**
     * @throws Throwable
     */
    public function handle(ChallengeRun $run): PilotMissionStats
    {
        return DB::transaction(function () use ($run): PilotMissionStats {
            /*
             * The row is created before it is read so that the read can lock
             * it, and the lock is what makes two of this pilot's runs on the
             * same mission land as two increments rather than one. The
             * recording path holds their progress row for the same pairing
             * and would serialize them anyway; this action does not get to
             * assume its caller does. The upsert makes the row exist and
             * takes the exclusive row lock, without disturbing the counters
             * on a row that already does.
             *
             * `updated_at` is named as the update column rather than left to
             * Eloquent to append. An empty list looks like it would emit an
             * `insertOrIgnore`, and it does not: `Query\Builder::upsert()`
             * runs a plain `insert()` when handed one, so the second run for
             * a pairing would abort the whole attempt on the unique key.
             * Today Eloquent hides that by appending `updated_at` itself
             * whenever the model uses timestamps — which makes the behaviour
             * hinge on a default that a derived table is a plausible
             * candidate to turn off.
             */
            PilotMissionStats::query()->upsert(
                [[
                    'user_id' => $run->user_id,
                    'challenge_id' => $run->challenge_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['user_id', 'challenge_id'],
                ['updated_at'],
            );

            $stats = PilotMissionStats::query()
                ->where('user_id', $run->user_id)
                ->where('challenge_id', $run->challenge_id)
                ->lockForUpdate()
                ->firstOrFail();

            $stats->runs++;
            $stats->best_score = max($stats->best_score, $run->score);
            $stats->collisions_total += $run->collisions;
            $stats->elapsed_seconds_total += $run->elapsed_seconds;
            $stats->last_run_id = $run->id;

            if ($run->collisions === 0) {
                $stats->clean_runs++;
            }

            if ($run->completed) {
                /*
                 * Frozen at the run that first cleared the mission.
                 * Everything flown afterwards is a pilot chasing stars on a
                 * mission they have already beaten, and counting it would
                 * make a pilot who kept practising look slower than one who
                 * moved on.
                 */
                $stats->attempts_to_clear ??= $stats->runs;
                $stats->cleared = true;
            }

            $stats->save();

            return $stats;
        });
    }
}
