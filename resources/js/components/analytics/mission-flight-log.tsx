import { ChartSpline } from 'lucide-react';
import { AttemptCurve } from '@/components/analytics/attempt-curve';
import { CohortMeter } from '@/components/analytics/cohort-meter';
import { DataBoundary } from '@/components/data-boundary';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import type { CurvePoint, MissionCohort } from '@/types/analytics';

export type MissionFlightLog = {
    curve: CurvePoint[];
    cohort: MissionCohort | null;
};

type MissionFlightLogPanelProps = {
    /**
     * `null` when the viewer's plan does not include advanced analytics, and
     * `undefined` while the deferred prop is still in flight. The server
     * decides which — this panel never infers entitlement from the props.
     */
    flightLog: MissionFlightLog | null | undefined;
    missionTitle: string;
    maxScore: number;
};

/**
 * The pilot's own history on the mission they are flying.
 *
 * Sits beside the cockpit rather than only on the analytics page because
 * this is where the question gets asked: a pilot who has just missed by ten
 * points wants to know whether they are closer than last time, and sending
 * them to another page to find out is how a feature goes unused.
 *
 * Renders nothing at all when the plan does not include it. A locked panel
 * would take cockpit space to advertise something the pricing page already
 * argues for, on the screen where the pilot is trying to concentrate.
 */
export function MissionFlightLogPanel({
    flightLog,
    missionTitle,
    maxScore,
}: MissionFlightLogPanelProps) {
    if (flightLog === null) {
        return null;
    }

    return (
        <Card>
            <CardHeader className="flex-row items-center gap-2 space-y-0">
                <ChartSpline
                    aria-hidden="true"
                    className="size-4 shrink-0 text-muted-foreground"
                />
                <CardTitle className="text-base">Your runs</CardTitle>
            </CardHeader>
            <CardContent className="space-y-6">
                <DataBoundary
                    data={flightLog}
                    skeleton={<Skeleton className="h-48 w-full" />}
                    empty={
                        <p className="text-sm text-muted-foreground">
                            Fly this mission once and your attempt curve starts
                            here.
                        </p>
                    }
                >
                    {(log) =>
                        log.curve.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Fly this mission once and your attempt curve
                                starts here.
                            </p>
                        ) : (
                            <>
                                <AttemptCurve
                                    maxScore={maxScore}
                                    missionTitle={missionTitle}
                                    points={log.curve}
                                />

                                {log.cohort && (
                                    <CohortMeter
                                        cohort={log.cohort}
                                        missionTitle={missionTitle}
                                    />
                                )}
                            </>
                        )
                    }
                </DataBoundary>
            </CardContent>
        </Card>
    );
}
