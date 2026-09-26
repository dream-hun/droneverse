<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Challenge;
use App\Models\ChallengeRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The numbers on the front page of the admin area.
 *
 * How many pilots there are and how many arrived this week, how much flying
 * the last day saw, and which missions took the most of it. Everything is
 * windowed — the last day, the last week, the last fortnight — so the cost of
 * a visit does not grow with the age of the platform, and every window reads
 * through an index: the users table by its size alone, the runs by the
 * `created_at` index added for exactly this.
 *
 * Money is not here. The overview is open to every member of staff and the
 * finance numbers are not, so revenue lives on its own page behind its own
 * permission rather than on a card some viewers would see and some not.
 */
final readonly class BuildAdminOverview
{
    /** Days of history the activity chart spans, today included. */
    private const int CHART_DAYS = 14;

    /** Missions listed as the busiest of the week. */
    private const int BUSIEST_LIMIT = 5;

    /**
     * @return array{
     *     pilots: array{total: int, newThisWeek: int, unverified: int, staff: int},
     *     flying: array{runs: int, activePilots: int, clearRate: float|null},
     *     online: int|null,
     *     daily: array<int, array{date: string, signups: int, runs: int}>,
     *     busiestMissions: array<int, array{challengeTitle: string, challengeSlug: string, courseTitle: string, courseSlug: string, runs: int, pilots: int, clearRate: float}>,
     * }
     */
    public function handle(): array
    {
        $now = CarbonImmutable::now();

        return [
            'pilots' => $this->pilots($now),
            'flying' => $this->flying($now),
            'online' => $this->online($now),
            'daily' => $this->daily($now),
            'busiestMissions' => $this->busiestMissions($now),
        ];
    }

    /**
     * @return array{total: int, newThisWeek: int, unverified: int, staff: int}
     */
    private function pilots(CarbonImmutable $now): array
    {
        $row = fluent(User::query()
            ->toBase()
            ->selectRaw(
                'count(*) as total, '
                .'count(case when created_at >= ? then 1 end) as new_this_week, '
                .'count(case when email_verified_at is null then 1 end) as unverified',
                [$now->subWeek()],
            )
            ->first());

        return [
            'total' => $row->integer('total'),
            'newThisWeek' => $row->integer('new_this_week'),
            'unverified' => $row->integer('unverified'),
            'staff' => User::query()->whereHas('roles')->count(),
        ];
    }

    /**
     * The last twenty-four hours of flying.
     *
     * The clear rate is null rather than zero on a day nobody flew: "no runs"
     * and "every run failed" are different things to be told.
     *
     * @return array{runs: int, activePilots: int, clearRate: float|null}
     */
    private function flying(CarbonImmutable $now): array
    {
        $row = fluent(ChallengeRun::query()
            ->toBase()
            ->where('created_at', '>=', $now->subDay())
            ->selectRaw(
                'count(*) as runs, '
                .'count(distinct user_id) as pilots, '
                .'count(case when completed = ? then 1 end) as clears',
                [true],
            )
            ->first());

        $runs = $row->integer('runs');

        return [
            'runs' => $runs,
            'activePilots' => $row->integer('pilots'),
            'clearRate' => $runs === 0 ? null : round($row->integer('clears') / $runs, 3),
        ];
    }

    /**
     * Signed-in pilots seen in the last five minutes.
     *
     * Read from the sessions table, so it is only an answer when sessions are
     * stored there. Any other driver keeps them somewhere this cannot count,
     * and null says so rather than claiming nobody is online.
     */
    private function online(CarbonImmutable $now): ?int
    {
        if (config('session.driver') !== 'database') {
            return null;
        }

        $table = config('session.table');

        return DB::table(is_string($table) ? $table : 'sessions')
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', $now->subMinutes(5)->getTimestamp())
            ->distinct()
            ->count('user_id');
    }

    /**
     * Sign-ups and runs per day, oldest first, with the quiet days filled in.
     *
     * A day nobody signed up is a zero on the chart, not a gap in it; the SQL
     * only returns the days something happened, so the calendar is built
     * here and the counts dropped onto it.
     *
     * @return array<int, array{date: string, signups: int, runs: int}>
     */
    private function daily(CarbonImmutable $now): array
    {
        $since = $now->subDays(self::CHART_DAYS - 1)->startOfDay();

        $signups = $this->countByDay(User::query(), $since);
        $runs = $this->countByDay(ChallengeRun::query(), $since);

        $days = [];

        for ($day = $since; $day->lte($now); $day = $day->addDay()) {
            $date = $day->toDateString();

            $days[] = [
                'date' => $date,
                'signups' => $signups[$date] ?? 0,
                'runs' => $runs[$date] ?? 0,
            ];
        }

        return $days;
    }

    /**
     * `date()` is spelled the same way by every driver this application runs
     * on, which is why the bucketing is done in SQL rather than by loading a
     * fortnight of rows to group them here.
     *
     * @param  Builder<User>|Builder<ChallengeRun>  $query
     * @return array<string, int>
     */
    private function countByDay(Builder $query, CarbonImmutable $since): array
    {
        return $query->toBase()
            ->where('created_at', '>=', $since)
            ->groupByRaw('date(created_at)')
            ->selectRaw('date(created_at) as day, count(*) as total')
            ->get()
            ->mapWithKeys(fn (stdClass $row): array => [
                (string) fluent($row)->string('day') => fluent($row)->integer('total'),
            ])
            ->all();
    }

    /**
     * The missions that took the most runs this week.
     *
     * Grouped over the runs first and joined to the catalogue second, so the
     * aggregate never carries a mission's environment or code along with it
     * and the lookup afterwards is five rows by primary key.
     *
     * @return array<int, array{challengeTitle: string, challengeSlug: string, courseTitle: string, courseSlug: string, runs: int, pilots: int, clearRate: float}>
     */
    private function busiestMissions(CarbonImmutable $now): array
    {
        $rows = ChallengeRun::query()
            ->toBase()
            ->where('created_at', '>=', $now->subWeek())
            ->groupBy('challenge_id')
            ->selectRaw(
                'challenge_id, '
                .'count(*) as runs, '
                .'count(distinct user_id) as pilots, '
                .'count(case when completed = ? then 1 end) as clears',
                [true],
            )
            ->orderByDesc('runs')
            ->orderBy('challenge_id')
            ->limit(self::BUSIEST_LIMIT)
            ->get();

        $challenges = Challenge::query()
            ->select(['id', 'course_id', 'title', 'slug'])
            ->with('course:id,title,slug')
            ->findMany($rows->pluck('challenge_id'))
            ->keyBy('id');

        return $rows
            ->map(function (stdClass $record) use ($challenges): ?array {
                $row = fluent($record);
                $challenge = $challenges->get($row->integer('challenge_id'));

                if (! $challenge instanceof Challenge) {
                    return null;
                }

                $runs = $row->integer('runs');

                return [
                    'challengeTitle' => $challenge->title,
                    'challengeSlug' => $challenge->slug,
                    'courseTitle' => $challenge->course->title,
                    'courseSlug' => $challenge->course->slug,
                    'runs' => $runs,
                    'pilots' => $row->integer('pilots'),
                    'clearRate' => $runs === 0 ? 0.0 : round($row->integer('clears') / $runs, 3),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
