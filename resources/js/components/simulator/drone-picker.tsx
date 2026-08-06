import { router } from '@inertiajs/react';
import { Check, Lock, Plane } from 'lucide-react';
import { useState } from 'react';
import { PlanUpgradeHint } from '@/components/plan-lock-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { update as updateDrone } from '@/routes/challenges/drone';
import type { DroneModelSummary } from '@/types/drone';

type DronePickerProps = {
    /** The airframe on the pad, as resolved server-side. */
    drone: DroneModelSummary;
    /** The fleet to choose from, or null when the viewer's plan cannot. */
    fleet: DroneModelSummary[] | null;
    courseSlug: string;
    challengeSlug: string;
    /** A run is in the air; the drone underneath it must not change. */
    disabled: boolean;
};

/**
 * How a drone's envelope reads next to another's.
 *
 * Four numbers rather than fifteen. The picker's job is to make the trade
 * legible at a glance — this one is quick and twitchy, that one is slow and
 * steady — and a table of every constant makes the two drones that differ in
 * one of them look as different as the two that differ in all.
 */
const SPECS = [
    {
        label: 'Cruise',
        read: (d: DroneModelSummary) => `${d.flight.cruiseSpeed} m/s`,
    },
    {
        label: 'Top speed',
        read: (d: DroneModelSummary) => `${d.flight.maxCruiseSpeed} m/s`,
    },
    {
        label: 'Agility',
        read: (d: DroneModelSummary) =>
            `${d.flight.horizontalAcceleration} m/s²`,
    },
    {
        label: 'Rotors',
        read: (d: DroneModelSummary) => String(d.airframe.rotors),
    },
] as const;

/**
 * Roughly how long this airframe stays up, at a working throttle.
 *
 * Battery drain is two numbers a pilot should not have to add up, and the
 * figure that actually matters is how many seconds of flying they buy. Taken
 * at 60% throttle because that is about what holding station and moving
 * between waypoints costs — the same assumption the throttle model in
 * use-drone-simulation makes when it settles at 0.55 in the cruise.
 */
function enduranceSeconds(drone: DroneModelSummary): number {
    const drain =
        drone.flight.batteryIdleDrain + drone.flight.batteryThrottleDrain * 0.6;

    return Math.round(100 / drain);
}

/**
 * The airframe selector in the mission cockpit.
 *
 * Gated on the Pro entitlement, and gated where it matters rather than here:
 * `fleet` is null for a pilot whose plan does not reach the feature because
 * the server did not send them one, and the endpoint this posts to is behind
 * the same Gate. What this component decides is only what to draw.
 *
 * Selecting posts to the server and lets the page prop come back changed,
 * rather than swapping the drone locally and telling the server afterwards.
 * The cockpit and the grader have to agree on which airframe flew, and the
 * only way they can is if neither of them is the one holding the answer.
 */
export function DronePicker({
    drone,
    fleet,
    courseSlug,
    challengeSlug,
    disabled,
}: DronePickerProps) {
    const [open, setOpen] = useState(false);
    const [saving, setSaving] = useState(false);

    if (!fleet) {
        return (
            <div className="flex items-center gap-2">
                <Badge variant="outline" className="gap-1.5">
                    <Lock aria-hidden="true" className="size-3" />
                    {drone.name}
                </Badge>
                <PlanUpgradeHint plan="pro" />
            </div>
        );
    }

    const select = (chosen: DroneModelSummary) => {
        if (chosen.id === drone.id) {
            setOpen(false);

            return;
        }

        setSaving(true);
        router.put(
            updateDrone.url([courseSlug, challengeSlug]),
            { drone: chosen.id },
            {
                preserveScroll: true,
                // The editor buffer and the console live in page state the
                // cockpit owns; only the drone needs to come back.
                only: ['drone'],
                onSuccess: () => setOpen(false),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm" disabled={disabled}>
                    <Plane /> {drone.name}
                </Button>
            </DialogTrigger>

            <DialogContent className="sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Choose your airframe</DialogTitle>
                    <DialogDescription>
                        Every mission is balanced around the{' '}
                        {fleet.find((d) => d.isDefault)?.name ?? 'stock drone'}.
                        The rest of the fleet trades one thing for another —
                        none of them is a straight upgrade.
                    </DialogDescription>
                </DialogHeader>

                <div
                    role="radiogroup"
                    aria-label="Airframe"
                    className="grid max-h-[60vh] gap-3 overflow-y-auto sm:grid-cols-2"
                >
                    {fleet.map((option) => {
                        const active = option.id === drone.id;

                        return (
                            <button
                                key={option.id}
                                type="button"
                                role="radio"
                                aria-checked={active}
                                disabled={saving}
                                onClick={() => select(option)}
                                className={cn(
                                    'rounded-lg border p-3 text-left transition-colors outline-none',
                                    'hover:bg-muted/50 focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                    'disabled:pointer-events-none disabled:opacity-60',
                                    active && 'border-primary bg-primary/5',
                                )}
                            >
                                <div className="flex items-center gap-2">
                                    <span className="font-medium">
                                        {option.name}
                                    </span>
                                    {active && (
                                        <Check
                                            aria-hidden="true"
                                            className="size-4 text-primary"
                                        />
                                    )}
                                    <Badge
                                        variant="secondary"
                                        className="ml-auto shrink-0"
                                    >
                                        {option.classLabel}
                                    </Badge>
                                </div>

                                <p className="mt-1.5 text-xs text-muted-foreground">
                                    {option.summary}
                                </p>

                                <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                                    {SPECS.map((spec) => (
                                        <div
                                            key={spec.label}
                                            className="flex justify-between gap-2"
                                        >
                                            <dt className="text-muted-foreground">
                                                {spec.label}
                                            </dt>
                                            <dd className="font-mono">
                                                {spec.read(option)}
                                            </dd>
                                        </div>
                                    ))}
                                    <div className="flex justify-between gap-2">
                                        <dt className="text-muted-foreground">
                                            Endurance
                                        </dt>
                                        <dd className="font-mono">
                                            ~{enduranceSeconds(option)}s
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-2">
                                        <dt className="text-muted-foreground">
                                            Span
                                        </dt>
                                        <dd className="font-mono">
                                            {(
                                                2 *
                                                (option.airframe.motorReach +
                                                    option.airframe.propRadius)
                                            ).toFixed(2)}
                                            m
                                        </dd>
                                    </div>
                                </dl>
                            </button>
                        );
                    })}
                </div>
            </DialogContent>
        </Dialog>
    );
}
