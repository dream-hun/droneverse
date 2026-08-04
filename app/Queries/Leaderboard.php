<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ChallengeStatus;
use App\Models\Course;
use App\Models\User;
use App\Models\UserChallengeProgress;
use App\Queries\Support\SliceCache;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Ranked pilot standings, and the per-pilot totals shown alongside them.
 *
 * This is a read model over {@see UserChallengeProgress}, not a second way
 * to write one. Ranking is a full aggregate over the largest table in the
 * schema and the board is read far more often than it changes, so every
 * view of it is cached here against a generation counter that
 * {@see self::forget()} bumps when a run moves a pilot's totals.
 *
 * It is deliberately not on the progress model. The entity is a row a pilot
 * owns and writes to; this is a cached, ranked projection over all of them,
 * with its own caching policy, lock tuning and raw SQL. Keeping them apart
 * means changing how the board is cached does not mean editing the model
 * that records attempts.
 */
final readonly class Leaderboard
{
    /**
     * How long a cached view of the leaderboard may live unattended.
     *
     * Recording a run retires the board explicitly, so this is only a
     * backstop for the things that move it without going through an attempt
     * — a pilot renaming themselves, a course being published or pulled.
     */
    private const int BOARD_TTL_SECONDS = 300;

    /**
     * The generation-keyed cache the board's slices live in.
     *
     * Defaulted rather than bound in the container: the prefix and the TTL
     * are this read model's policy and nothing outside it has an opinion on
     * them, so a caller resolving a Leaderboard should not have to know the
     * cache exists.
     */
    public function __construct(
        private SliceCache $cache = new SliceCache('leaderboard', self::BOARD_TTL_SECONDS),
    ) {}

    /**
     * Completed-challenge counts for the given user, keyed by course id.
     *
     * Only currently playable content counts (published challenge in a
     * published course), so a count can never exceed the published-challenge
     * total displayed beside it.
     *
     * @return Collection<int, int>
     */
    public function completedCountsByCourse(User $user): Collection
    {
        return $this->playableFor($user)
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
    public function statsFor(User $user): array
    {
        $row = $this->playableFor($user)
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
    public function standings(User $viewer, ?Course $course = null, int $limit = 25): Collection
    {
        $rows = $this->remember(
            sprintf('standings:%s:%d', $this->scopeKey($course), $limit),
            fn (): array => $this->standingRows(
                $this->standingsQuery($course)->limit($limit)->get()->all(),
            ),
        );

        return (new Collection($rows))
            ->map(fn (array $row): array => $this->toStanding($row, $viewer));
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
    public function standingFor(User $viewer, ?Course $course = null): ?array
    {
        $row = $this->remember(
            sprintf('standing:%s:%d', $this->scopeKey($course), $viewer->id),
            function () use ($course, $viewer): ?array {
                $row = DB::query()
                    ->fromSub($this->standingsQuery($course), 'standings')
                    ->where('user_id', $viewer->id)
                    ->first();

                return $row === null ? null : $this->standingRows([$row])[0];
            },
            // Keyed to one pilot, so the only requests that can collide on
            // it are that pilot's own. There is no pile-up here to hold a
            // lock against, and taking one would just be two more cache
            // writes on the miss.
            shared: false,
        );

        return $row === null ? null : $this->toStanding($row, $viewer);
    }

    /**
     * How many pilots the board is ranking, so a rank can be read as "of N".
     *
     * Counted straight off the playable set. The ranking window in
     * {@see self::standingsQuery()} orders and numbers pilots but cannot
     * change how many distinct ones there are, so the grouping aggregate
     * never has to run to answer this.
     */
    public function rankedPilotCount(?Course $course = null): int
    {
        return $this->remember(
            sprintf('pilots:%s', $this->scopeKey($course)),
            fn (): int => $this->inCourse($this->playable(), $course)
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
    public function forget(): void
    {
        $this->cache->flush();
    }

    /**
     * Cache a slice of the board against the current generation.
     *
     * A thin pass-through to {@see SliceCache}, which carries the reasoning
     * behind the generation counter, the wrapped values and the build lock.
     * The one decision that stays here is `$shared`: only this class knows
     * which of its slices every viewer reads and which belong to one pilot.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $compute
     * @return TValue
     */
    private function remember(string $key, Closure $compute, bool $shared = true): mixed
    {
        return $this->cache->remember($key, $compute, $shared);
    }

    /**
     * Cache-key fragment naming the slice of the board being read.
     *
     * The overall board and each per-course board are separate populations,
     * so they must never share an entry.
     */
    private function scopeKey(?Course $course): string
    {
        return $course instanceof Course ? (string) $course->id : 'all';
    }

    /**
     * Progress rows for content that is still playable today.
     *
     * Every aggregate here reads through this, so "playable" means exactly
     * one thing everywhere: a published challenge inside a published course.
     * Retiring either end retires the progress from all of them at once,
     * with no predicate left behind to drift out of step.
     *
     * @return EloquentBuilder<UserChallengeProgress>
     */
    private function playable(): EloquentBuilder
    {
        return UserChallengeProgress::query()
            ->join('challenges', 'challenges.id', '=', 'user_challenge_progress.challenge_id')
            ->join('courses', 'courses.id', '=', 'challenges.course_id')
            ->where('challenges.is_published', true)
            ->where('courses.is_published', true);
    }

    /**
     * {@see self::playable()} narrowed to one pilot.
     *
     * @return EloquentBuilder<UserChallengeProgress>
     */
    private function playableFor(User $user): EloquentBuilder
    {
        return $this->playable()->where('user_challenge_progress.user_id', $user->id);
    }

    /**
     * Narrow a playable-progress query to one course, or leave it global.
     *
     * @param  EloquentBuilder<UserChallengeProgress>  $query
     * @return EloquentBuilder<UserChallengeProgress>
     */
    private function inCourse(EloquentBuilder $query, ?Course $course): EloquentBuilder
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
    private function standingsQuery(?Course $course = null): QueryBuilder
    {
        $totals = $this->inCourse($this->playable(), $course)
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
     * Ranked rows reduced to plain values, ready to be cached.
     *
     * Nothing with a class may cross the cache boundary. Every serializing
     * store — database, file, redis, memcached — unserializes through
     * `cache.serializable_classes`, which this application leaves at the
     * framework default of `false` so that a leaked APP_KEY cannot be
     * turned into a gadget chain. A cached query row therefore comes back
     * as __PHP_Incomplete_Class and fatals on first use, which is what
     * caching the raw stdClass rows did: the board rendered on the miss
     * that populated the cache and then threw on every hit until the entry
     * expired. Only the array store, which the test suite uses and which
     * keeps the live object, could not see it.
     *
     * @param  array<int, stdClass>  $rows
     * @return array<int, array{user_id: int, name: string, points: int, stars: int, completed: int, place: int}>
     */
    private function standingRows(array $rows): array
    {
        return array_map(fn (stdClass $row): array => [
            'user_id' => (int) $row->user_id,
            'name' => (string) $row->name,
            'points' => (int) $row->points,
            'stars' => (int) $row->stars,
            'completed' => (int) $row->completed,
            'place' => (int) $row->place,
        ], $rows);
    }

    /**
     * @param  array{user_id: int, name: string, points: int, stars: int, completed: int, place: int}  $row
     * @return array{rank: int, name: string, points: int, stars: int, completed: int, isYou: bool}
     */
    private function toStanding(array $row, User $viewer): array
    {
        return [
            'rank' => $row['place'],
            'name' => $row['name'],
            'points' => $row['points'],
            'stars' => $row['stars'],
            'completed' => $row['completed'],
            'isYou' => $row['user_id'] === $viewer->id,
        ];
    }
}
