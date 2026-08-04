import type { MissionCohort } from '@/types/analytics';

type CohortMeterProps = {
    cohort: MissionCohort;
    missionTitle: string;
};

/**
 * Where this pilot's best sits among everyone who has flown the mission.
 *
 * A single number with a position, so it is a hero figure and a meter rather
 * than a chart — a one-value distribution has no shape worth plotting, and a
 * bar chart of one bar is just a number wearing a costume.
 *
 * The meter is a magnitude, so it is filled from a zero baseline. The marker
 * sits at the percentile itself rather than at the pilot's score: the axis
 * here is the field, not the scoreboard.
 */
export function CohortMeter({ cohort, missionTitle }: CohortMeterProps) {
    const alone = cohort.pilots <= 1;

    return (
        <div className="space-y-3">
            <div className="flex items-baseline gap-2">
                <span className="text-3xl font-semibold tabular-nums">
                    {alone ? '—' : `${cohort.percentile}%`}
                </span>
                <span className="text-sm text-muted-foreground">
                    {alone
                        ? 'You are the only pilot to have flown this'
                        : `of pilots beaten on ${missionTitle}`}
                </span>
            </div>

            {!alone && (
                <div
                    aria-label={`You beat ${cohort.percentile}% of the ${cohort.pilots} pilots who have flown ${missionTitle}`}
                    aria-valuemax={100}
                    aria-valuemin={0}
                    aria-valuenow={cohort.percentile}
                    className="relative h-2 overflow-hidden rounded-full bg-muted"
                    role="meter"
                >
                    <div
                        className="h-full rounded-full bg-chart-3 dark:bg-chart-1"
                        style={{ width: `${cohort.percentile}%` }}
                    />
                </div>
            )}

            <dl className="grid grid-cols-3 gap-3 text-sm">
                <div>
                    <dt className="text-xs text-muted-foreground">Your best</dt>
                    <dd className="font-medium tabular-nums">
                        {cohort.yourBest}
                    </dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">Top score</dt>
                    <dd className="font-medium tabular-nums">
                        {cohort.topBest}
                    </dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">Pilots</dt>
                    <dd className="font-medium tabular-nums">
                        {cohort.pilots}
                    </dd>
                </div>
            </dl>
        </div>
    );
}
