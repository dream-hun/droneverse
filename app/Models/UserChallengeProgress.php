<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChallengeStatus;
use Carbon\CarbonInterface;
use Database\Factories\UserChallengeProgressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * @property int $id
 * @property int $user_id
 * @property int $challenge_id
 * @property ChallengeStatus $status
 * @property int $best_score
 * @property int $stars
 * @property string|null $last_code
 * @property int $attempts
 * @property CarbonInterface|null $completed_at
 */
#[Fillable(['user_id', 'challenge_id', 'status', 'best_score', 'stars', 'last_code', 'attempts', 'completed_at'])]
#[Table(name: 'user_challenge_progress')]
final class UserChallengeProgress extends Model
{
    /** @use HasFactory<UserChallengeProgressFactory> */
    use HasFactory;

    /**
     * Completed-challenge counts for the given user, keyed by course id.
     *
     * Only currently playable content counts (published challenge in a
     * published course), so a count can never exceed the published-challenge
     * total displayed beside it.
     *
     * @return Collection<int, int>
     */
    public static function completedCountsByCourse(User $user): Collection
    {
        return self::query()
            ->join('challenges', 'challenges.id', '=', 'user_challenge_progress.challenge_id')
            ->join('courses', 'courses.id', '=', 'challenges.course_id')
            ->where('user_challenge_progress.user_id', $user->id)
            ->where('user_challenge_progress.status', ChallengeStatus::Completed)
            ->where('challenges.is_published', true)
            ->where('courses.is_published', true)
            ->selectRaw('challenges.course_id as course_id, count(*) as completed')
            ->groupBy('challenges.course_id')
            ->pluck('completed', 'course_id');
    }

    /**
     * Aggregate completion stats for the given user, in a single query.
     *
     * Scoped to currently playable content, matching the per-course counts
     * shown alongside these totals.
     *
     * @return array{completed: int, stars: int}
     */
    public static function statsFor(User $user): array
    {
        $row = self::query()
            ->join('challenges', 'challenges.id', '=', 'user_challenge_progress.challenge_id')
            ->join('courses', 'courses.id', '=', 'challenges.course_id')
            ->where('user_challenge_progress.user_id', $user->id)
            ->where('challenges.is_published', true)
            ->where('courses.is_published', true)
            ->selectRaw(
                'count(case when user_challenge_progress.status = ? then 1 end) as completed, coalesce(sum(user_challenge_progress.stars), 0) as stars',
                [ChallengeStatus::Completed->value],
            )
            ->first();

        return [
            'completed' => (int) ($row->completed ?? 0),
            'stars' => (int) ($row->stars ?? 0),
        ];
    }

    /**
     * The leading pilots, best first.
     *
     * Only pilots who have actually flown appear; the board is a record of
     * simulator time, not a roster of everyone who signed up.
     *
     * @return Collection<int, array{rank: int, name: string, points: int, stars: int, completed: int, isYou: bool}>
     */
    public static function standings(User $viewer, ?Course $course = null, int $limit = 25): Collection
    {
        return self::standingsQuery($course)
            ->limit($limit)
            ->get()
            ->map(fn (stdClass $row): array => self::toStanding($row, $viewer));
    }

    /**
     * The viewer's own row, wherever they placed.
     *
     * Ranked against every pilot rather than only the listed ones, so a
     * pilot who fell outside {@see self::standings()} still learns where
     * they stand. Null only until they fly a mission that still counts.
     *
     * @return array{rank: int, name: string, points: int, stars: int, completed: int, isYou: bool}|null
     */
    public static function standingFor(User $viewer, ?Course $course = null): ?array
    {
        $row = DB::query()
            ->fromSub(self::standingsQuery($course), 'standings')
            ->where('user_id', $viewer->id)
            ->first();

        return $row === null ? null : self::toStanding($row, $viewer);
    }

    /**
     * How many pilots the board is ranking, so a rank can be read as "of N".
     */
    public static function rankedPilotCount(?Course $course = null): int
    {
        return DB::query()
            ->fromSub(self::standingsQuery($course), 'standings')
            ->count();
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

    /**
     * The leaderboard population, ranked and ordered.
     *
     * Points, stars and completions are summed per pilot over currently
     * playable content only, so retiring a mission retires its score too.
     * `rank()` leaves pilots level on all three metrics sharing a rank,
     * while the row order breaks the tie in favour of whoever finished
     * first. "rank" and "position" are reserved words in MySQL, hence
     * `place`.
     */
    private static function standingsQuery(?Course $course = null): Builder
    {
        $totals = self::query()
            ->join('challenges', 'challenges.id', '=', 'user_challenge_progress.challenge_id')
            ->join('courses', 'courses.id', '=', 'challenges.course_id')
            ->join('users', 'users.id', '=', 'user_challenge_progress.user_id')
            ->where('challenges.is_published', true)
            ->where('courses.is_published', true)
            ->groupBy('users.id', 'users.name')
            ->selectRaw(
                'users.id as user_id, users.name as name, '
                .'coalesce(sum(user_challenge_progress.best_score), 0) as points, '
                .'coalesce(sum(user_challenge_progress.stars), 0) as stars, '
                .'count(case when user_challenge_progress.status = ? then 1 end) as completed, '
                .'max(user_challenge_progress.completed_at) as finished_at',
                [ChallengeStatus::Completed->value],
            );

        if ($course instanceof Course) {
            $totals->where('challenges.course_id', $course->id);
        }

        return DB::query()
            ->fromSub($totals->toBase(), 'totals')
            ->select('user_id', 'name', 'points', 'stars', 'completed')
            ->selectRaw('rank() over (order by points desc, stars desc, completed desc) as place')
            ->orderByDesc('points')
            ->orderByDesc('stars')
            ->orderByDesc('completed')
            ->orderByRaw('finished_at is null, finished_at')
            ->orderBy('user_id');
    }

    /**
     * @return array{rank: int, name: string, points: int, stars: int, completed: int, isYou: bool}
     */
    private static function toStanding(stdClass $row, User $viewer): array
    {
        return [
            'rank' => (int) $row->place,
            'name' => (string) $row->name,
            'points' => (int) $row->points,
            'stars' => (int) $row->stars,
            'completed' => (int) $row->completed,
            'isYou' => (int) $row->user_id === $viewer->id,
        ];
    }
}
