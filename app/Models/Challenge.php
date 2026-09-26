<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\CourseContent;
use App\Enums\ChallengeStatus;
use App\Observers\ChallengeObserver;
use Database\Factories\ChallengeFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A flyable mission attached to a course.
 *
 * The two JSON columns are shaped after EnvironmentConfig and SuccessCriteria
 * in resources/js/types/simulator.ts, which is the contract the simulator
 * flies to, and CourseSeeder is checked against these shapes. Keys the grader
 * falls back on a default for are optional here even where the client
 * requires them, because the server does not get to assume the client's
 * guarantees.
 *
 * @phpstan-type Waypoint array{x: float|int, y: float|int, z: float|int, radius: float|int}
 * @phpstan-type Gate array{x: float|int, y: float|int, z: float|int, width: float|int, height: float|int, rotationY: float|int}
 * @phpstan-type Obstacle array{type: string, x: float|int, y: float|int, z: float|int, sx?: float|int, sy?: float|int, sz?: float|int, radius?: float|int, height?: float|int, rotationY?: float|int, label?: string}
 * @phpstan-type Prop array{kind: string, x: float|int, z: float|int, rotationY?: float|int, color?: string, label?: string}
 * @phpstan-type Carwash array{x: float|int, z: float|int, rotationY?: float|int, width?: float|int, height?: float|int, length?: float|int, label?: string}
 * @phpstan-type PhotoTarget array{x: float|int, z: float|int, radius: float|int, label?: string}
 * @phpstan-type Environment array{start: array{x: float|int, y: float|int, z: float|int, yaw: float|int}, bounds: array{width: float|int, depth: float|int, height: float|int}, goal: array{x: float|int, z: float|int, radius: float|int}, gates: array<int, Gate>, waypoints: array<int, Waypoint>, obstacles?: array<int, Obstacle>, props?: array<int, Prop>, carwash?: Carwash, wind?: array{speed?: float|int, directionDeg?: float|int}}
 * @phpstan-type SuccessCriteria array{type: string, max_time_seconds: float|int, waypoints?: array<int, Waypoint>, avoid_collisions?: bool, landing_required?: bool, min_altitude?: float|int, min_photos?: int, photo_targets?: array<int, PhotoTarget>, wash_required?: bool}
 *
 * @property int $id
 * @property int $course_id
 * @property string $title
 * @property string $slug
 * @property string $briefing
 * @property int $order
 * @property string $difficulty
 * @property string|null $required_plan
 * @property string $starter_code
 * @property string|null $solution_code
 * @property Environment $environment
 * @property SuccessCriteria $success_criteria
 * @property int $max_score
 * @property bool $is_published
 * @property-read Course $course
 * @property-read int|null $progress_count
 */
#[Hidden(['solution_code'])]
#[ObservedBy(ChallengeObserver::class)]
final class Challenge extends Model
{
    use CourseContent;

    /** @use HasFactory<ChallengeFactory> */
    use HasFactory;

    /**
     * Runs a pilot may make before the reference solution unlocks.
     *
     * Completing the mission unlocks it immediately; this is the escape
     * hatch for pilots who are stuck rather than done.
     */
    public const int ATTEMPTS_BEFORE_SOLUTION = 3;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Whether the reference solution may be shown for this progress record.
     *
     * A mission with no authored solution never unlocks, so the panel can
     * key off this single answer.
     */
    public function solutionUnlockedBy(?UserChallengeProgress $progress): bool
    {
        if ($this->solution_code === null) {
            return false;
        }

        if (! $progress instanceof UserChallengeProgress) {
            return false;
        }

        return $progress->status === ChallengeStatus::Completed
            || $progress->attempts >= self::ATTEMPTS_BEFORE_SOLUTION;
    }

    /**
     * @return HasMany<UserChallengeProgress, $this>
     */
    public function progress(): HasMany
    {
        return $this->hasMany(UserChallengeProgress::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => 'array',
            'success_criteria' => 'array',
            'is_published' => 'boolean',
            'order' => 'integer',
            'max_score' => 'integer',
        ];
    }

    /**
     * @param  Builder<Challenge>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true);
    }
}
