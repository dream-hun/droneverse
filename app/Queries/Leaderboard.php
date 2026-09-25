<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\Course;
use App\Models\PilotCourseTotals;
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
 * This is a read model over {@see UserChallengeProgress}, not a second way to
 * write one — but it no longer reads that table directly. Ranking is a full
 * aggregate and the board is read far more often than it changes, so it is
 * served from two things instead: {@see PilotCourseTotals}, which collapses a
 * pilot's progress in a course to a single row as it is written, and a cache
 * over that, keyed against generation counters that {@see self::forgetCourse()}
 * bumps when a run moves a pilot's totals.
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
     * The scope naming the board drawn from every course at once.
     *
     * Every run bumps it, because every run can move it. Per-course boards
     * get a scope of their own from {@see self::courseScope()}.
     */
    private const string OVERALL_SCOPE = 'all';

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
        // Already one row per course, so this is a lookup rather than an
        // aggregate: the count the rollup keeps is the count being asked for.
        return $this->playableFor($user)
            ->get(['pilot_course_totals.course_id', 'pilot_course_totals.completed'])
            ->mapWithKeys(fn (PilotCourseTotals $totals): array => [$totals->course_id => $totals->completed]);
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
        $row = fluent($this->playableFor($user)
            ->selectRaw(
                'coalesce(sum(pilot_course_totals.completed), 0) as completed, '
                .'coalesce(sum(pilot_course_totals.stars), 0) as stars',
            )
            ->toBase()
            ->first());

        return [
            'completed' => $row->integer('completed'),
            'stars' => $row->integer('stars'),
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
            sprintf('standings:%d', $limit),
            [$this->scopeFor($course)],
            fn (): array => $this->standingRows(
                $this->standingsQuery($course)->limit($limit)->get()->all(),
            ),
        );

        return new Collection($rows)
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
            sprintf('standing:%d', $viewer->id),
            // Scoped to the board rather than to the viewer: a rank is a
            // statement about where this pilot sits among all of them, so
            // anyone's run can move it.
            [$this->scopeFor($course)],
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
            'pilots',
            [$this->scopeFor($course)],
            fn (): int => $this->inCourse($this->playable(), $course)
                ->distinct()
                ->count('pilot_course_totals.user_id'),
        );
    }

    /**
     * Retire the cached views of the board a run in this course could move.
     *
     * Two of them, and only two. A run inside a course changes that course's
     * board and the overall one; it cannot reorder a board drawn from a
     * different course's missions, and those go on being served. That is the
     * whole reason the counter is per board rather than per read model —
     * previously any run anywhere retired every course's board at once, so
     * the busiest course's traffic decided how often the quietest one paid
     * for its ranking aggregate.
     *
     * The overall board is still retired by every run, because every run
     * really can move it. What stops that from becoming a stampede is the
     * build lock in {@see SliceCache}: the viewers who miss together queue
     * behind one rebuild instead of each running their own.
     */
    public function forgetCourse(int $courseId): void
    {
        $this->cache->flush(self::OVERALL_SCOPE, $this->courseScope($courseId));
    }

    /**
     * Cache a slice of the board against the generation of the board it reads.
     *
     * A thin pass-through to {@see SliceCache}, which carries the reasoning
     * behind the generation counters, the wrapped values and the build lock.
     * Two decisions stay here: which board a slice reads, and `$shared` —
     * only this class knows which of its slices every viewer reads and which
     * belong to one pilot.
     *
     * @template TValue
     *
     * @param  list<string>  $scopes
     * @param  Closure(): TValue  $compute
     * @return TValue
     */
    private function remember(string $key, array $scopes, Closure $compute, bool $shared = true): mixed
    {
        return $this->cache->remember($key, $scopes, $compute, $shared);
    }

    /**
     * The board a slice is drawn from.
     *
     * The overall board and each per-course board are separate populations,
     * so they never share an entry and never retire each other. The scope is
     * part of the cache key as well as the invalidation unit, so naming it
     * here is all that keeps the two in step.
     */
    private function scopeFor(?Course $course): string
    {
        return $course instanceof Course
            ? $this->courseScope($course->id)
            : self::OVERALL_SCOPE;
    }

    private function courseScope(int $courseId): string
    {
        return sprintf('course:%d', $courseId);
    }

    /**
     * Course totals for content that is still playable today.
     *
     * Every aggregate here reads through this, so "playable" means exactly
     * one thing everywhere. Half of it is enforced by the join below —
     * retiring a course takes its totals off the board at once, with no
     * predicate left behind to drift out of step. The other half, whether an
     * individual mission is published, is already folded into the stored
     * totals by {@see \App\Actions\RollUpCourseTotals}, which is why
     * {@see \App\Observers\ChallengeObserver} has to rebuild a course when
     * that changes.
     *
     * @return EloquentBuilder<PilotCourseTotals>
     */
    private function playable(): EloquentBuilder
    {
        return PilotCourseTotals::query()
            ->join('courses', 'courses.id', '=', 'pilot_course_totals.course_id')
            ->where('courses.is_published', true);
    }

    /**
     * {@see self::playable()} narrowed to one pilot.
     *
     * @return EloquentBuilder<PilotCourseTotals>
     */
    private function playableFor(User $user): EloquentBuilder
    {
        return $this->playable()->where('pilot_course_totals.user_id', $user->id);
    }

    /**
     * Narrow a playable-totals query to one course, or leave it global.
     *
     * @param  EloquentBuilder<PilotCourseTotals>  $query
     * @return EloquentBuilder<PilotCourseTotals>
     */
    private function inCourse(EloquentBuilder $query, ?Course $course): EloquentBuilder
    {
        if ($course instanceof Course) {
            $query->where('pilot_course_totals.course_id', $course->id);
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
     *
     * The group-by is over {@see PilotCourseTotals} rather than over every
     * progress row in the schema, which divides the ranking scan by the
     * number of missions in a course — and for a single-course board leaves
     * no grouping to do at all, since the rollup already holds one row per
     * pilot there.
     */
    private function standingsQuery(?Course $course = null): QueryBuilder
    {
        $totals = $this->inCourse($this->playable(), $course)
            ->join('users', 'users.id', '=', 'pilot_course_totals.user_id')
            ->groupBy('users.id', 'users.name')
            ->selectRaw(
                'users.id as user_id, users.name as name, '
                .'coalesce(sum(pilot_course_totals.points), 0) as points, '
                .'coalesce(sum(pilot_course_totals.stars), 0) as stars, '
                .'coalesce(sum(pilot_course_totals.completed), 0) as completed, '
                .'max(pilot_course_totals.finished_at) as finished_at',
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
        return array_map(function (stdClass $record): array {
            $row = fluent($record);

            return [
                'user_id' => $row->integer('user_id'),
                'name' => $row->string('name')->value(),
                'points' => $row->integer('points'),
                'stars' => $row->integer('stars'),
                'completed' => $row->integer('completed'),
                'place' => $row->integer('place'),
            ];
        }, $rows);
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
