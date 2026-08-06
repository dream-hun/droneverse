<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ChallengeRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One graded run, exactly as it was scored.
 *
 * The append-only counterpart to {@see UserChallengeProgress}. Progress is
 * the pilot's standing on a mission and it only ever improves; a run is what
 * actually happened on one flight, including the ones that went badly. Every
 * question analytics asks — how a score moved, how many attempts a mission
 * takes, whether the collisions are coming down — is a question about these
 * rows, and none of them survive the merge into progress.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $challenge_id
 * @property int|null $drone_model_id
 * @property int $score
 * @property int $stars
 * @property bool $completed
 * @property int $objectives_hit
 * @property int $objectives_total
 * @property int $collisions
 * @property float $elapsed_seconds
 * @property bool $landed
 * @property bool $timed_out
 * @property CarbonInterface|null $created_at
 */
#[Fillable([
    'user_id',
    'challenge_id',
    'drone_model_id',
    'score',
    'stars',
    'completed',
    'objectives_hit',
    'objectives_total',
    'collisions',
    'elapsed_seconds',
    'landed',
    'timed_out',
])]
final class ChallengeRun extends Model
{
    /** @use HasFactory<ChallengeRunFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * Nothing revises a graded run, so there is no `updated_at` to keep.
     */
    public const UPDATED_AT = null;

    /**
     * A run is addressed publicly by its uuid, never by its id.
     *
     * Same reasoning as {@see DronePhoto}: the auto-increment key is what the
     * foreign keys and the pilot/mission index are built on and it stays,
     * but an id in a URL is a running count of every run every pilot has ever
     * flown, handed to anyone holding one of their own.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * The columns Eloquent fills with a generated identifier on insert.
     *
     * Overridden because HasUuids assumes the uuid *is* the primary key.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

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
     * The airframe this run was flown in.
     *
     * Null on every run flown before the fleet existed, and on any run whose
     * drone has since been retired from it. Both mean the same thing to a
     * reader — the airframe of the day — and neither is worth losing the run
     * over, which is why the column nulls out rather than cascading.
     *
     * @return BelongsTo<DroneModel, $this>
     */
    public function drone(): BelongsTo
    {
        return $this->belongsTo(DroneModel::class, 'drone_model_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'stars' => 'integer',
            'completed' => 'boolean',
            'objectives_hit' => 'integer',
            'objectives_total' => 'integer',
            'collisions' => 'integer',
            'elapsed_seconds' => 'float',
            'landed' => 'boolean',
            'timed_out' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
