<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Challenge;
use App\Queries\FlightLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AnalyticsController extends Controller
{
    /**
     * Display the pilot's flight analytics.
     *
     * Gated by `can:advanced_analytics` on the route rather than here — the
     * capability is sold on Pro and the Gate is registered from the Feature
     * enum, so the middleware is the whole check.
     */
    public function __invoke(Request $request, FlightLog $flightLog): Response
    {
        $user = $request->user();

        $missions = $flightLog->flownMissions($user);

        // An unknown slug falls back to the most recently flown mission
        // rather than 404ing, so an old bookmark and a mission that has since
        // been retired both still show something.
        $selected = $this->selectedMission($request->query('mission'), $missions);

        return Inertia::render('analytics', [
            'summary' => $flightLog->summaryFor($user),
            'missions' => $missions,
            /*
             * The mission's own ceiling travels with it. Inferring the axis
             * from the runs themselves would scale the chart to whatever the
             * pilot happened to score, so a mission nobody has half-cleared
             * would look nearly beaten.
             */
            'selected' => $selected === null ? null : [
                'slug' => $selected->slug,
                'title' => $selected->title,
                'maxScore' => $selected->max_score,
            ],
            /*
             * The curve and the cohort are the two aggregates on this page
             * that read a whole mission's runs, and they sit below the fold.
             * Deferring them lets the summary and the weak-spot list paint
             * first, and a pilot who has flown nothing never pays for either.
             */
            'curve' => $selected === null
                ? []
                : Inertia::defer(fn (): array => $flightLog->missionCurve($user, $selected)),
            'cohort' => $selected === null
                ? null
                : Inertia::defer(fn (): ?array => $flightLog->cohortFor($user, $selected)),
            'weakSpots' => $flightLog->weakSpots($user),
        ]);
    }

    /**
     * The mission whose curve is being shown.
     *
     * Resolved against the pilot's own flown missions, so a slug naming a
     * mission they have never flown — or one they cannot reach — selects
     * nothing rather than drawing an empty chart with a real title above it.
     *
     * The lookup names the course as well, because a challenge slug is only
     * unique within its course: `landing-pad` in two courses is two missions,
     * and matching on the challenge slug alone would sometimes chart the
     * wrong one.
     *
     * @param  array<int, array{challengeSlug: string, courseSlug: string}>  $missions
     */
    private function selectedMission(mixed $slug, array $missions): ?Challenge
    {
        $wanted = null;

        if (is_string($slug)) {
            $wanted = collect($missions)->firstWhere('challengeSlug', $slug);
        }

        $wanted ??= $missions[0] ?? null;

        if ($wanted === null) {
            return null;
        }

        return Challenge::query()
            ->where('slug', $wanted['challengeSlug'])
            ->whereRelation('course', 'slug', $wanted['courseSlug'])
            ->first();
    }
}
