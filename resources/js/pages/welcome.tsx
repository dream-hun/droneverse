import { Head, Link, usePage } from '@inertiajs/react';
import {
    Camera,
    Code2,
    Gauge,
    Radar,
    Route as RouteIcon,
    Trophy,
} from 'lucide-react';
import { LazyDroneShowcase } from '@/components/marketing/lazy-drone-showcase';
import { SiteFooter } from '@/components/marketing/site-footer';
import { SiteHeader } from '@/components/marketing/site-header';
import { dashboard, leaderboard, register } from '@/routes';
import { index as coursesIndex, show as showCourse } from '@/routes/courses';
import type { DroneModelSummary } from '@/types/drone';
import type { MarketingCourse } from '@/types/simulator';

/**
 * The pitch cards. Only the last one quotes the catalog, so the whole list is
 * built per render rather than kept as a module constant.
 */
function buildFeatures(missionCount: number) {
    return [
        {
            icon: Code2,
            title: 'A real JavaScript API',
            description:
                'takeoff, moveTo, turn, setAltitude — async/await against a real drone interface. Loops and conditionals work, because it is real code.',
        },
        {
            icon: Gauge,
            title: 'Honest physics',
            description:
                'Mass, momentum, thrust and collisions are simulated, not animated. Overshoot a waypoint and you will feel exactly why.',
        },
        {
            icon: Radar,
            title: 'Sensors on board',
            description:
                'Probe with getDistanceAhead, read telemetry from getPosition, sweep with scan. Fly by feedback instead of a fixed script.',
        },
        {
            icon: Trophy,
            title: 'Every run is graded',
            description:
                'Accuracy, collisions and flight time are scored the moment your drone touches down. No guesswork about what went wrong.',
        },
        {
            icon: Camera,
            title: 'A photo log that fills up',
            description:
                'Survey missions call takePhoto from your code, and every frame the drone captures lands in your own gallery.',
        },
        {
            icon: RouteIcon,
            title: `${missionCount} missions, one flight path`,
            description:
                'From a first hover to a full urban shift: delivery routes, racing gates, search patterns and rooftop surveys.',
        },
    ];
}

const API_METHODS = [
    'takeoff()',
    'moveForward()',
    'moveTo()',
    'turn()',
    'setAltitude()',
    'setSpeed()',
    'hover()',
    'getPosition()',
    'getDistanceAhead()',
    'scan()',
    'takePhoto()',
    'land()',
];

const SAMPLE_CODE = `async function main(drone) {
    await drone.takeoff();

    // Creep forward until the rangefinder sees the wall.
    while ((await drone.getDistanceAhead()) > 1.5) {
        await drone.moveForward(0.5);
    }

    await drone.turn(90);
    await drone.moveForward(4);
    await drone.land();
}`;

/** Small caps label that opens each section, matching the header rhythm. */
function SectionLabel({ children }: { children: string }) {
    return (
        <h2 className="mb-12 font-mono text-xs tracking-widest text-primary uppercase">
            {children}
        </h2>
    );
}

type WelcomeProps = {
    /** The fleet's default airframe, turning in the hero. */
    drone: DroneModelSummary;
    courses: MarketingCourse[];
    missionCount: number;
};

export default function Welcome({
    courses,
    missionCount,
    drone,
}: WelcomeProps) {
    const { auth } = usePage().props;

    const firstCourse = courses.at(0);
    const features = buildFeatures(missionCount);
    const heroStats = [
        { value: String(courses.length), label: 'Courses' },
        { value: String(missionCount), label: 'Missions' },
        { value: '0', label: 'Repair bills' },
    ];

    return (
        <>
            <Head title="DroneVerse | Learn Drone Programming" />

            <div className="theme-droneverse dark min-h-screen bg-background font-sans text-foreground selection:bg-primary selection:text-primary-foreground">
                <SiteHeader />

                <section className="border-b border-border">
                    <div className="mx-auto grid min-h-[85vh] max-w-7xl px-6 lg:grid-cols-2">
                        <div className="flex animate-entry flex-col justify-center border-border py-12 lg:border-r lg:py-20 lg:pr-16">
                            <h1 className="mb-6 text-5xl font-extrabold tracking-tighter text-balance uppercase lg:text-7xl">
                                Learn to fly,{' '}
                                <span className="text-muted-foreground">
                                    one line at a time.
                                </span>
                            </h1>

                            <p className="mb-12 max-w-md text-lg leading-relaxed text-muted-foreground">
                                DroneVerse teaches drone programming in the
                                browser: write JavaScript, pilot a
                                physics-simulated quadcopter through real
                                missions, and get scored on every run.
                            </p>

                            <div className="mb-12 flex flex-wrap items-center gap-4">
                                <Link
                                    href={auth.user ? dashboard() : register()}
                                    className="bg-primary px-6 py-4 font-bold tracking-widest text-primary-foreground uppercase transition-all hover:brightness-110"
                                >
                                    {auth.user
                                        ? 'Go to dashboard'
                                        : 'Start learning free'}
                                </Link>
                                <Link
                                    href={coursesIndex()}
                                    className="border border-border px-6 py-4 font-mono text-xs tracking-widest text-foreground uppercase transition-colors hover:border-primary hover:text-primary"
                                >
                                    Browse courses →
                                </Link>
                            </div>

                            <dl className="grid max-w-md grid-cols-3 gap-6 border-t border-border pt-8">
                                {heroStats.map((stat) => (
                                    <div
                                        key={stat.label}
                                        className="flex flex-col-reverse"
                                    >
                                        <dt className="mt-1 font-mono text-xs tracking-widest text-muted-foreground uppercase">
                                            {stat.label}
                                        </dt>
                                        <dd className="text-4xl font-extrabold tracking-tighter">
                                            {stat.value}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </div>

                        <div className="relative grid place-items-center overflow-hidden bg-surface">
                            <div
                                aria-hidden
                                className="pointer-events-none absolute inset-0 grid-dots opacity-30"
                            />
                            <div className="relative z-10 w-4/5 py-16 transition-transform duration-700 hover:scale-[1.02]">
                                <div className="aspect-square min-w-0 overflow-hidden rounded-2xl bg-surface-elevated outline outline-1 -outline-offset-1 outline-white/5">
                                    <LazyDroneShowcase drone={drone} />
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <main className="mx-auto max-w-7xl space-y-24 px-6 py-24">
                    <section id="why" className="scroll-mt-24">
                        <SectionLabel>Why DroneVerse</SectionLabel>

                        <div className="mb-12 max-w-3xl">
                            <h3 className="mb-4 text-3xl font-bold tracking-tighter uppercase lg:text-4xl">
                                The only flight school where crashing is the
                                point.
                            </h3>
                            <p className="leading-relaxed text-muted-foreground">
                                Fly the same manoeuvre twenty times until the
                                code is right. Nothing breaks, nothing costs
                                anything, and every attempt tells you exactly
                                where you lost the score.
                            </p>
                        </div>

                        <div className="grid gap-1 md:grid-cols-2 lg:grid-cols-3">
                            {features.map((feature) => (
                                <div
                                    key={feature.title}
                                    className="group border border-border bg-white/[0.02] p-6 transition-colors hover:bg-white/[0.04]"
                                >
                                    <div className="mb-5 flex size-10 items-center justify-center border border-border text-primary">
                                        <feature.icon className="size-5" />
                                    </div>
                                    <h4 className="mb-3 text-xl font-bold tracking-tight uppercase">
                                        {feature.title}
                                    </h4>
                                    <p className="text-sm leading-relaxed text-muted-foreground">
                                        {feature.description}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </section>

                    <section id="cockpit" className="scroll-mt-24">
                        <SectionLabel>The cockpit</SectionLabel>

                        <div className="grid gap-8 md:grid-cols-2">
                            <div className="flex flex-col justify-center">
                                <h3 className="mb-4 text-3xl font-bold tracking-tighter uppercase">
                                    This is what a <br /> mission looks like.
                                </h3>
                                <p className="mb-6 leading-relaxed text-muted-foreground">
                                    Every mission hands you a starter script and
                                    the full drone API. Your code runs in a
                                    sandboxed worker driving a real physics sim
                                    — so a loop that reads a sensor and reacts
                                    to it does exactly what it would on an
                                    airframe.
                                </p>

                                <ul className="flex flex-wrap gap-1">
                                    {API_METHODS.map((method) => (
                                        <li
                                            key={method}
                                            className="border border-border bg-white/[0.02] px-3 py-1 font-mono text-xs text-muted-foreground"
                                        >
                                            {method}
                                        </li>
                                    ))}
                                </ul>
                            </div>

                            <div className="rounded-lg border border-border bg-surface-elevated p-6 font-mono text-sm leading-relaxed shadow-2xl">
                                <div aria-hidden className="mb-4 flex gap-2">
                                    <div className="size-2.5 rounded-full bg-red-500/20" />
                                    <div className="size-2.5 rounded-full bg-yellow-500/20" />
                                    <div className="size-2.5 rounded-full bg-green-500/20" />
                                </div>
                                <div className="text-code-comment">
                                    // wall-follow.js
                                </div>
                                <pre className="mt-2 overflow-x-auto text-xs leading-relaxed">
                                    <code>{SAMPLE_CODE}</code>
                                </pre>
                            </div>
                        </div>
                    </section>

                    {/* Nothing published means nothing to advertise; the rest of the pitch still stands. */}
                    {courses.length > 0 && (
                        <section>
                            <SectionLabel>The flight path</SectionLabel>

                            <div className="mb-12 max-w-3xl">
                                <h3 className="mb-4 text-3xl font-bold tracking-tighter uppercase lg:text-4xl">
                                    {courses.length} courses, ground up.
                                </h3>
                                <p className="leading-relaxed text-muted-foreground">
                                    Start with a hover you can hold. Finish
                                    flying a full shift over a city block. Every
                                    course is free and unlocks the moment you
                                    make an account.
                                </p>
                            </div>

                            <ol className="grid gap-1">
                                {courses.map((course, index) => (
                                    <li key={course.slug}>
                                        <Link
                                            href={showCourse(course.slug)}
                                            className="group block border border-border bg-white/[0.02] p-6 transition-colors hover:bg-white/[0.04] md:p-8"
                                        >
                                            <div className="flex flex-wrap items-baseline justify-between gap-4">
                                                <div>
                                                    <span
                                                        aria-hidden
                                                        className="mb-1 block font-mono text-xs text-muted-foreground"
                                                    >
                                                        COURSE{' '}
                                                        {String(
                                                            index + 1,
                                                        ).padStart(2, '0')}
                                                    </span>
                                                    <h4 className="text-xl font-bold tracking-tight uppercase">
                                                        {course.title}
                                                    </h4>
                                                </div>
                                                <span className="font-mono text-xs tracking-widest text-primary uppercase">
                                                    {course.difficulty} ·{' '}
                                                    {course.challengesCount}{' '}
                                                    {course.challengesCount ===
                                                    1
                                                        ? 'mission'
                                                        : 'missions'}
                                                </span>
                                            </div>

                                            <p className="mt-6 text-sm leading-relaxed text-muted-foreground">
                                                {course.description}
                                            </p>
                                        </Link>
                                    </li>
                                ))}
                            </ol>
                        </section>
                    )}

                    <section className="border border-primary bg-primary/5 p-8 md:p-12">
                        <h3 className="mb-4 text-3xl font-bold tracking-tighter uppercase">
                            Ready to fly?
                        </h3>
                        <p className="mb-8 max-w-xl leading-relaxed text-muted-foreground">
                            {firstCourse
                                ? `Start with ${firstCourse.title} — no experience required, no hardware to buy, nothing to break.`
                                : 'No experience required, no hardware to buy, nothing to break.'}
                        </p>
                        <div className="flex flex-wrap gap-4">
                            <Link
                                href={auth.user ? coursesIndex() : register()}
                                className="bg-primary px-6 py-4 font-bold tracking-widest text-primary-foreground uppercase transition-all hover:brightness-110"
                            >
                                {auth.user
                                    ? 'Browse courses'
                                    : 'Create your free account'}
                            </Link>
                            <Link
                                href={leaderboard()}
                                className="border border-border px-6 py-4 font-mono text-xs tracking-widest text-foreground uppercase transition-colors hover:border-primary hover:text-primary"
                            >
                                See the leaderboard →
                            </Link>
                        </div>
                    </section>
                </main>

                <SiteFooter courses={courses} />
            </div>
        </>
    );
}
