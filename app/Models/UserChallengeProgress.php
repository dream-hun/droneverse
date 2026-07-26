<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChallengeStatus;
use Carbon\CarbonInterface;
use Closure;
use Database\Factories\UserChallengeProgressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
     * How long a cached view of the leaderboard may live unattended.
     *
     * Recording a run retires the board explicitly, so this is only a
     * backstop for the things that move it without going through an attempt
     * — a pilot renaming themselves, a course being published or pulled.
     */
    private const int BOARD_TTL_SECONDS = 300;

    private const string BOARD_GENERATION_KEY = 'leaderboard:generation';

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
        return self::playableFor($user)
            ->where('user_challenge_progress.status', ChallengeStatus::Completed)
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
        $row = self::playableFor($user)
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
     * The leading pilots, the best first.
     *
     * Only pilots who have actually flown appear; the board is a record of
     * simulator time, not a roster of everyone who signed up.
     *
     * @return Collection<int, array{rank: int, name: string, points: int, stars: int, completed: int, isYou: bool}>
     */
    public static function standings(User $viewer, ?Course $course = null, int $limit = 25): Collection
    {
        $rows = self::remember(
            sprintf('standings:%s:%d', self::scopeKey($course), $limit),
            fn (): Collection => self::standingsQuery($course)->limit($limit)->get(),
        );

        return $rows->map(fn (stdClass $row): array => self::toStanding($row, $viewer));
    }

    /**
     * The viewer's own row, wherever they placed.
     *
     * Ranked against every pilot rather than only the listed ones, so a
     * pilot who fell outside {@see self::standings()} still learns where
     * they stand. Null only until they fly a mission that still counts.
     *
     * A caller already holding a page of {@see self::standings()} should
     * prefer the viewer's row from that page when it is present: it is
     * identical to this one and saves re-running the ranking aggregate.
     *
     * @return array{rank: int, name: string, points: int, stars: int, completed: int, isYou: bool}|null
     */
    public static function standingFor(User $viewer, ?Course $course = null): ?array
    {
        $row = self::remember(
            sprintf('standing:%s:%d', self::scopeKey($course), $viewer->id),
            fn (): ?stdClass => DB::query()
                ->fromSub(self::standingsQuery($course), 'standings')
                ->where('user_id', $viewer->id)
                ->first(),
        );

        return $row === null ? null : self::toStanding($row, $viewer);
    }

    /**
     * How many pilots the board is ranking, so a rank can be read as "of N".
     *
     * Counted straight off the playable set. The ranking window in
     * {@see self::standingsQuery()} orders and numbers pilots but cannot
     * change how many distinct ones there are, so the grouping aggregate
     * never has to run to answer this.
     */
    public static function rankedPilotCount(?Course $course = null): int
    {
        return self::remember(
            sprintf('pilots:%s', self::scopeKey($course)),
            fn (): int => self::inCourse(self::playable(), $course)
                ->distinct()
                ->count('user_challenge_progress.user_id'),
        );
    }

    /**
     * Retire every cached view of the board.
     *
     * Called when a run changes a pilot's totals. Rather than tracking which
     * of the per-course and per-viewer entries a given run could have moved,
     * the generation counter is bumped and every old key simply stops being
     * looked up — the stale entries age out on their own.
     */
    public static function forgetBoard(): void
    {
        Cache::increment(self::BOARD_GENERATION_KEY);
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
     * Cache a slice of the board against the current generation.
     *
     * Ranking is a full aggregate over the largest table in the schema, and
     * the board is read far more often than it changes, so every view of it
     * goes through here. The generation is part of the key, which is what
     * makes {@see self::forgetBoard()} a single write rather than a hunt for
     * every entry a run might have invalidated.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $compute
     * @return TValue
     */
    private static function remember(string $key, Closure $compute): mixed
    {
        $generation = Cache::get(self::BOARD_GENERATION_KEY, 0);

        return Cache::remember(
            sprintf('leaderboard:%s:%s', $generation, $key),
            self::BOARD_TTL_SECONDS,
            $compute,
        );
    }

    /**
     * Cache-key fragment naming the slice of the board being read.
     *
     * The overall board and each per-course board are separate populations,
     * so they must never share an entry.
     */
    private static function scopeKey(?Course $course): string
    {
        return $course instanceof Course ? (string) $course->id : 'all';
    }

    /**
     * Progress rows for content that is still playable today.
     *
     * Every aggregate on this model reads through here, so "playable" means
     * exactly one thing everywhere: a published challenge inside a published
     * course. Retiring either end retires the progress from all of them at
     * once, with no predicate left behind to drift out of step.
     *
     * @return EloquentBuilder<self>
     */
    private static function playable(): EloquentBuilder
    {
        return self::query()
            ->join('challenges', 'challenges.id', '=', 'user_challenge_progress.challenge_id')
            ->join('courses', 'courses.id', '=', 'challenges.course_id')
            ->where('challenges.is_published', true)
            ->where('courses.is_published', true);
    }

    /**
     * {@see self::playable()} narrowed to one pilot.
     *
     * @return EloquentBuilder<self>
     */
    private static function playableFor(User $user): EloquentBuilder
    {
        return self::playable()->where('user_challenge_progress.user_id', $user->id);
    }

    /**
     * Narrow a playable-progress query to one course, or leave it global.
     *
     * @param  EloquentBuilder<self>  $query
     * @return EloquentBuilder<self>
     */
    private static function inCourse(EloquentBuilder $query, ?Course $course): EloquentBuilder
    {
        if ($course instanceof Course) {
            $query->where('challenges.course_id', $course->id);
        }

        return $query;
    }

    /**
     * The leaderboard population, ranked and ordered.
     *
     * Points, stars and completions are summed per pilot over currently
     * playable content only, so retiring a mission retires its score too.
     * `rank()` leaves pilots level on all three metrics sharing a rank,
     * while the row order breaks the tie in favor of whoever finished
     * first. "rank" and "position" are reserved words in MySQL, hence
     * `place`.
     */
    private static function standingsQuery(?Course $course = null): QueryBuilder
    {
        $totals = self::inCourse(self::playable(), $course)
            ->join('users', 'users.id', '=', 'user_challenge_progress.user_id')
            ->groupBy('users.id', 'users.name')
            ->selectRaw(
                'users.id as user_id, users.name as name, '
                .'coalesce(sum(user_challenge_progress.best_score), 0) as points, '
                .'coalesce(sum(user_challenge_progress.stars), 0) as stars, '
                .'count(case when user_challenge_progress.status = ? then 1 end) as completed, '
                .'max(user_challenge_progress.completed_at) as finished_at',
                [ChallengeStatus::Completed->value],
            );

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
