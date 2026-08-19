<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\PilotMissionStatsFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one pilot's runs on one mission add up to.
 *
 * A derived row, and only ever a derived row. {@see ChallengeRun} is the
 * record of what happened; this is the running total that
 * {@see \App\Actions\RollUpChallengeRun} keeps beside it so that reading a
 * pilot's analytics does not mean re-reading their whole flying career.
 * {@see \App\Actions\RebuildRollups} recomputes every column from the runs,
 * and if the two ever disagree the runs are right.
 *
 * Plural on purpose: a row is not one statistic, it is the set of them for a
 * pairing, and `PilotMissionStat` would name something that does not exist.
 *
 * Never addressed by URL, so there is no route key to give it — nothing
 * outside the read models and their rebuild knows this table is here.
 *
 * @property int $id
 * @property int $user_id
 * @property int $challenge_id
 * @property int $runs
 * @property int $clean_runs
 * @property bool $cleared
 * @property int $best_score
 * @property int $collisions_total
 * @property float $elapsed_seconds_total
 * @property int|null $attempts_to_clear
 * @property int|null $last_run_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'user_id',
    'challenge_id',
    'runs',
    'clean_runs',
    'cleared',
    'best_score',
    'collisions_total',
    'elapsed_seconds_total',
    'attempts_to_clear',
    'last_run_id',
])]
#[Table(name: 'pilot_mission_stats')]
final class PilotMissionStats extends Model
{
    /** @use HasFactory<PilotMissionStatsFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Challenge, $this>
     */
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'runs' => 'integer',
            'clean_runs' => 'integer',
            'cleared' => 'boolean',
            'best_score' => 'integer',
            'collisions_total' => 'integer',
            'elapsed_seconds_total' => 'float',
            'attempts_to_clear' => 'integer',
            'last_run_id' => 'integer',
        ];
    }
}
