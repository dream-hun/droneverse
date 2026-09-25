<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChallengeStatus;
use App\Observers\UserChallengeProgressObserver;
use Carbon\CarbonInterface;
use Database\Factories\UserChallengeProgressFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $challenge_id
 * @property int|null $drone_model_id
 * @property ChallengeStatus $status
 * @property int $best_score
 * @property int $stars
 * @property string|null $last_code
 * @property int $attempts
 * @property CarbonInterface|null $completed_at
 */
#[Table(name: 'user_challenge_progress')]
#[ObservedBy(UserChallengeProgressObserver::class)]
final class UserChallengeProgress extends Model
{
    /** @use HasFactory<UserChallengeProgressFactory> */
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
     * The airframe this pilot has chosen for this mission.
     *
     * Null until they choose one, which is the state every pilot without the
     * Pro entitlement stays in. {@see \App\Actions\ResolveMissionDrone} reads
     * that as the fleet default rather than as an error.
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
            'status' => ChallengeStatus::class,
            'best_score' => 'integer',
            'stars' => 'integer',
            'attempts' => 'integer',
            'completed_at' => 'datetime',
        ];
    }
}
