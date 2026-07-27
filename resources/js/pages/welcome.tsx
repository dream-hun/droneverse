import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    ArrowUpRight,
    Camera,
    Code2,
    Gauge,
    Radar,
    Route as RouteIcon,
    Trophy,
} from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { LazyDroneShowcase } from '@/components/marketing/lazy-drone-showcase';
import { Button } from '@/components/ui/button';
import { dashboard, leaderboard, login, register } from '@/routes';
import { index as coursesIndex, show as showCourse } from '@/routes/courses';
import { edit as editProfile } from '@/routes/profile';
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

/**
 * The footer columns.
 *
 * The catalog column names real courses, so it follows whatever is published
 * rather than a hand-kept list; the account column follows the header, showing
 * sign-up prompts to a guest and the pilot's own settings to everyone else.
 */
function buildFooterGroups(courses: MarketingCourse[], isSignedIn: boolean) {
    return [
        {
            heading: 'Flight school',
            links: [
                { label: 'All courses', href: coursesIndex() },
                ...courses.slice(0, 3).map((course) => ({
                    label: course.title,
                    href: showCourse(course.slug),
                })),
            ],
        },
        {
            heading: 'Cockpit',
            links: [
                { label: 'Dashboard', href: dashboard() },
                { label: 'Leaderboard', href: leaderboard() },
            ],
        },
        {
            heading: 'Account',
            links: isSignedIn
                ? [{ label: 'Profile settings', href: editProfile() }]
                : [
                      { label: 'Log in', href: login() },
                      { label: 'Create an account', href: register() },
                  ],
        },
    ];
}

/** Small caps label that opens each section, matching the header rhythm. */
function SectionLabel({ children }: { children: string }) {
    return (
        <p className="text-xs font-medium tracking-[0.2em] text-muted-foreground uppercase">
            {children}
        </p>
    );
}

/**
 * The app's quadcopter mark on its tile, matching how the dashboard sidebar
 * badges it — the mark alone loses its rotors at wordmark size.
 */
function Wordmark() {
    return (
        <span className="flex items-center gap-2.5 font-semibold tracking-tight">
            <span className="flex aspect-square size-8 items-center justify-center rounded-md bg-foreground">
                <AppLogoIcon
                    aria-hidden
                    className="size-5 fill-current text-background"
                />
            </span>
            DroneVerse
        </span>
    );
}

type WelcomeProps = {
    courses: MarketingCourse[];
    missionCount: number;
};

export default function Welcome({ courses, missionCount }: WelcomeProps) {
    const { auth } = usePage().props;

    const firstCourse = courses.at(0);
    const features = buildFeatures(missionCount);
    const footerGroups = buildFooterGroups(courses, Boolean(auth.user));
    const heroStats = [
        { value: String(courses.length), label: 'Courses' },
        { value: String(missionCount), label: 'Missions' },
        { value: '0', label: 'Repair bills' },
    ];

    return (
        <>
            <Head title="DroneVerse — Learn Drone Programming" />

            <div className="min-h-screen bg-[oklch(0.985_0_0)] text-foreground dark:bg-background">
                <header className="sticky top-0 z-30 border-b border-border/70 bg-[oklch(0.985_0_0)]/80 backdrop-blur dark:bg-background/80">
                    <nav className="mx-auto flex max-w-6xl items-center justify-between px-6 py-5">
                        <Link href="/" aria-label="DroneVerse home">
                            <Wordmark />
                        </Link>

                        <div className="hidden gap-8 text-sm text-muted-foreground md:flex">
                            <a
                                href="#why"
                                className="transition hover:text-foreground"
                            >
                                Why DroneVerse
                            </a>
                            <a
                                href="#cockpit"
                                className="transition hover:text-foreground"
                            >
                                The cockpit
                            </a>
                            <Link
                                href={coursesIndex()}
                                className="transition hover:text-foreground"
                            >
                                Courses
                            </Link>
                        </div>

                        <div className="flex items-center gap-4 text-sm">
                            {auth.user ? (
                                <Link href={dashboard()}>
                                    <Button className="h-9 rounded-full px-4">
                                        Dashboard
                                    </Button>
                                </Link>
                            ) : (
                                <>
                                    <Link
                                        href={login()}
                                        className="hidden text-muted-foreground transition hover:text-foreground sm:inline"
                                    >
                                        Log in
                                    </Link>
                                    <Link href={register()}>
                                        <Button className="h-9 rounded-full px-4">
                                            Start free
                                        </Button>
                                    </Link>
                                </>
                            )}
                        </div>
                    </nav>
                </header>

                <main>
                    <section className="mx-auto max-w-6xl px-6 pt-16 pb-20 md:pt-24 md:pb-28">
                        <div className="grid items-center gap-12 md:grid-cols-2 md:gap-16">
                            <div>
                                <span className="inline-flex items-center gap-2 rounded-full border border-border px-3 py-1 text-xs tracking-[0.16em] text-muted-foreground uppercase">
                                    <span
                                        aria-hidden
                                        className="inline-block size-1.5 rounded-full bg-foreground"
                                    />
                                    {missionCount} missions · free to start
                                </span>

                                <h1 className="mt-6 font-heading text-5xl leading-[1.02] font-semibold tracking-tight md:text-6xl">
                                    Learn to fly, one line at a time.
                                </h1>

                                <p className="mt-6 max-w-md text-base leading-relaxed text-muted-foreground md:text-lg">
                                    DroneVerse teaches drone programming in the
                                    browser: write JavaScript, pilot a
                                    physics-simulated quadcopter through real
                                    missions, and get scored on every run.
                                </p>

                                <div className="mt-8 flex flex-wrap items-center gap-3">
                                    <Link
                                        href={
                                            auth.user ? dashboard() : register()
                                        }
                                    >
                                        <Button className="h-11 gap-2 rounded-full px-5">
                                            {auth.user
                                                ? 'Go to dashboard'
                                                : 'Start learning free'}
                                            <ArrowRight />
                                        </Button>
                                    </Link>
                                    <Link href={coursesIndex()}>
                                        <Button
                                            variant="outline"
                                            className="h-11 rounded-full px-5"
                                        >
                                            Browse courses
                                        </Button>
                                    </Link>
                                </div>

                                <dl className="mt-12 grid max-w-md grid-cols-3 gap-6 border-t border-border pt-8">
                                    {heroStats.map((stat) => (
                                        <div
                                            key={stat.label}
                                            className="flex flex-col-reverse"
                                        >
                                            <dt className="mt-1 text-xs tracking-[0.14em] text-muted-foreground uppercase">
                                                {stat.label}
                                            </dt>
                                            <dd className="font-heading text-3xl font-semibold tracking-tight">
                                                {stat.value}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </div>

                            <div className="relative">
                                <div className="aspect-square min-w-0 overflow-hidden rounded-3xl border border-border bg-gradient-to-br from-slate-900 to-slate-800 shadow-[0_20px_60px_-24px_oklch(0.145_0_0/0.45)]">
                                    <LazyDroneShowcase />
                                </div>
                            </div>
                        </div>
                    </section>

                    <section
                        id="why"
                        className="mx-auto max-w-6xl scroll-mt-24 px-6 py-20 md:py-24"
                    >
                        <div className="max-w-2xl">
                            <SectionLabel>Why DroneVerse</SectionLabel>
                            <h2 className="mt-3 font-heading text-4xl font-semibold tracking-tight md:text-5xl">
                                The only flight school where crashing is the
                                point.
                            </h2>
                            <p className="mt-4 text-base text-muted-foreground md:text-lg">
                                Fly the same manoeuvre twenty times until the
                                code is right. Nothing breaks, nothing costs
                                anything, and every attempt tells you exactly
                                where you lost the score.
                            </p>
                        </div>

                        <div className="mt-12 grid gap-4 md:grid-cols-3">
                            {features.map((feature) => (
                                <div
                                    key={feature.title}
                                    className="rounded-2xl border border-border bg-card p-6 transition hover:border-foreground/30"
                                >
                                    <div className="flex size-10 items-center justify-center rounded-lg border border-border">
                                        <feature.icon className="size-5" />
                                    </div>
                                    <h3 className="mt-5 font-heading text-base font-semibold tracking-tight">
                                        {feature.title}
                                    </h3>
                                    <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                        {feature.description}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </section>

                    <section
                        id="cockpit"
                        className="mx-auto max-w-6xl scroll-mt-24 px-6 py-20 md:py-24"
                    >
                        <div className="grid items-start gap-12 md:grid-cols-2 md:gap-16">
                            <div>
                                <SectionLabel>The cockpit</SectionLabel>
                                <h2 className="mt-3 font-heading text-4xl font-semibold tracking-tight md:text-5xl">
                                    This is what a mission looks like.
                                </h2>
                                <p className="mt-4 text-base text-muted-foreground md:text-lg">
                                    Every mission hands you a starter script and
                                    the full drone API. Your code runs in a
                                    sandboxed worker driving a real physics sim
                                    — so a loop that reads a sensor and reacts
                                    to it does exactly what it would on an
                                    airframe.
                                </p>

                                <ul className="mt-8 flex flex-wrap gap-2">
                                    {API_METHODS.map((method) => (
                                        <li
                                            key={method}
                                            className="rounded-full border border-border bg-card px-3 py-1 font-mono text-xs text-muted-foreground"
                                        >
                                            {method}
                                        </li>
                                    ))}
                                </ul>
                            </div>

                            <div className="overflow-hidden rounded-2xl border border-border bg-slate-950 shadow-[0_20px_60px_-24px_oklch(0.145_0_0/0.45)]">
                                <div className="flex items-center gap-2 border-b border-white/10 px-4 py-3">
                                    <span
                                        aria-hidden
                                        className="size-2.5 rounded-full bg-white/20"
                                    />
                                    <span
                                        aria-hidden
                                        className="size-2.5 rounded-full bg-white/20"
                                    />
                                    <span
                                        aria-hidden
                                        className="size-2.5 rounded-full bg-white/20"
                                    />
                                    <span className="ml-2 font-mono text-xs text-slate-400">
                                        wall-follow.js
                                    </span>
                                </div>
                                <pre className="overflow-x-auto p-5 text-xs leading-relaxed text-slate-200">
                                    <code>{SAMPLE_CODE}</code>
                                </pre>
                            </div>
                        </div>
                    </section>

                    {/* Nothing published means nothing to advertise; the rest of the pitch still stands. */}
                    {courses.length > 0 && (
                        <section className="mx-auto max-w-6xl px-6 py-20 md:py-24">
                            <div className="max-w-2xl">
                                <SectionLabel>The flight path</SectionLabel>
                                <h2 className="mt-3 font-heading text-4xl font-semibold tracking-tight md:text-5xl">
                                    {courses.length} courses, ground up.
                                </h2>
                                <p className="mt-4 text-base text-muted-foreground md:text-lg">
                                    Start with a hover you can hold. Finish
                                    flying a full shift over a city block. Every
                                    course is free and unlocks the moment you
                                    make an account.
                                </p>
                            </div>

                            <ol className="mt-12 divide-y divide-border overflow-hidden rounded-2xl border border-border bg-card">
                                {courses.map((course, index) => (
                                    <li key={course.slug}>
                                        <Link
                                            href={showCourse(course.slug)}
                                            className="group flex flex-col gap-4 p-6 transition hover:bg-secondary/60 sm:flex-row sm:items-center sm:gap-8 sm:p-8"
                                        >
                                            <span
                                                aria-hidden
                                                className="font-heading text-sm font-semibold tracking-[0.16em] text-muted-foreground"
                                            >
                                                {String(index + 1).padStart(
                                                    2,
                                                    '0',
                                                )}
                                            </span>

                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-3">
                                                    <h3 className="font-heading text-lg font-semibold tracking-tight">
                                                        {course.title}
                                                    </h3>
                                                    <span className="rounded-full border border-border px-2.5 py-0.5 text-xs text-muted-foreground capitalize">
                                                        {course.difficulty}
                                                    </span>
                                                    <span className="text-xs text-muted-foreground">
                                                        {course.challengesCount}{' '}
                                                        {course.challengesCount ===
                                                        1
                                                            ? 'mission'
                                                            : 'missions'}
                                                    </span>
                                                </div>
                                                <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                                                    {course.description}
                                                </p>
                                            </div>

                                            <ArrowUpRight className="size-5 shrink-0 text-muted-foreground transition group-hover:text-foreground" />
                                        </Link>
                                    </li>
                                ))}
                            </ol>
                        </section>
                    )}

                    <section className="border-t border-border/70">
                        <div className="mx-auto max-w-6xl px-6 py-20 text-center md:py-28">
                            <h2 className="font-heading text-4xl font-semibold tracking-tight md:text-5xl">
                                Ready to fly?
                            </h2>
                            <p className="mx-auto mt-4 max-w-xl text-base text-muted-foreground md:text-lg">
                                {firstCourse
                                    ? `Start with ${firstCourse.title} — no experience required, no hardware to buy, nothing to break.`
                                    : 'No experience required, no hardware to buy, nothing to break.'}
                            </p>
                            <div className="mt-8 flex justify-center">
                                <Link
                                    href={
                                        auth.user ? coursesIndex() : register()
                                    }
                                >
                                    <Button className="h-11 gap-2 rounded-full px-6">
                                        {auth.user
                                            ? 'Browse courses'
                                            : 'Create your free account'}
                                        <ArrowRight />
                                    </Button>
                                </Link>
                            </div>
                        </div>
                    </section>
                </main>

                <footer className="border-t border-border/70">
                    <div className="mx-auto grid max-w-6xl gap-10 px-6 py-14 md:grid-cols-4">
                        <div>
                            <Wordmark />
                            <p className="mt-4 max-w-xs text-sm text-muted-foreground">
                                Drone programming you can actually fly. Written
                                in JavaScript, graded on real physics.
                            </p>
                        </div>

                        {footerGroups.map((group) => (
                            <div key={group.heading}>
                                <h4 className="font-heading text-sm font-semibold tracking-tight">
                                    {group.heading}
                                </h4>
                                <ul className="mt-4 space-y-2 text-sm text-muted-foreground">
                                    {group.links.map((link) => (
                                        <li key={link.label}>
                                            <Link
                                                href={link.href}
                                                className="transition hover:text-foreground"
                                            >
                                                {link.label}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>

                    <div className="border-t border-border/70">
                        <div className="mx-auto flex max-w-6xl flex-col items-start justify-between gap-2 px-6 py-6 text-xs text-muted-foreground sm:flex-row sm:items-center">
                            <p>© {new Date().getFullYear()} DroneVerse.</p>
                            <p>Built with Laravel, Inertia and three.js.</p>
                        </div>
                    </div>
                </footer>
            </div>
        </>
    );
}
