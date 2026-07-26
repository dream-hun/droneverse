import { Head, Link, usePage } from '@inertiajs/react';
import { Code2, Gauge, Trophy } from 'lucide-react';
import { LazyDroneShowcase } from '@/components/marketing/lazy-drone-showcase';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';
import { index as coursesIndex } from '@/routes/courses';

const STEPS = [
    {
        icon: Code2,
        title: 'Write real JavaScript',
        description:
            'Control a drone with an async/await API: takeoff, moveForward, turn, sensors, and more. No fake pseudo-code.',
    },
    {
        icon: Gauge,
        title: 'Watch it fly for real',
        description:
            'Your code runs against an actual physics simulation — mass, momentum, and collisions, not scripted animation.',
    },
    {
        icon: Trophy,
        title: 'Get graded, keep flying',
        description:
            'Every challenge scores your run on accuracy, collisions, and time, and tracks your progress across courses.',
    },
];

const SAMPLE_CODE = `async function main(drone) {
    await drone.takeoff();
    await drone.moveForward(5);
    await drone.turn(90);
    await drone.moveForward(5);
    await drone.land();
}`;

export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="DroneVerse — Learn Drone Programming" />

            <div className="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <header className="mx-auto flex max-w-6xl items-center justify-between px-6 py-6">
                    <span className="text-lg font-semibold">DroneVerse</span>

                    <nav className="flex items-center gap-4 text-sm">
                        <Link href={coursesIndex()} className="hover:underline">
                            Courses
                        </Link>
                        {auth.user ? (
                            <Link href={dashboard()}>
                                <Button size="sm">Dashboard</Button>
                            </Link>
                        ) : (
                            <>
                                <Link
                                    href={login()}
                                    className="hover:underline"
                                >
                                    Log in
                                </Link>
                                <Link href={register()}>
                                    <Button size="sm">Get started</Button>
                                </Link>
                            </>
                        )}
                    </nav>
                </header>

                <main className="mx-auto max-w-6xl px-6">
                    <section className="grid items-center gap-10 py-12 lg:grid-cols-2 lg:py-20">
                        <div>
                            <h1 className="text-4xl font-bold tracking-tight lg:text-5xl">
                                Learn to fly, one line at a time.
                            </h1>
                            <p className="mt-4 max-w-md text-lg text-muted-foreground">
                                DroneVerse teaches drone programming in the
                                browser: write JavaScript, pilot a
                                physics-simulated quadcopter through real
                                challenges, and level up as you go.
                            </p>
                            <div className="mt-8 flex gap-3">
                                <Link
                                    href={auth.user ? dashboard() : register()}
                                >
                                    <Button size="lg">
                                        {auth.user
                                            ? 'Go to dashboard'
                                            : 'Start learning free'}
                                    </Button>
                                </Link>
                                <Link href={coursesIndex()}>
                                    <Button size="lg" variant="outline">
                                        Browse courses
                                    </Button>
                                </Link>
                            </div>
                        </div>

                        <div className="aspect-square min-w-0 overflow-hidden rounded-2xl border bg-gradient-to-br from-slate-900 to-slate-800 shadow-lg">
                            <LazyDroneShowcase />
                        </div>
                    </section>

                    <section className="grid gap-6 py-12 sm:grid-cols-3">
                        {STEPS.map((step) => (
                            <div
                                key={step.title}
                                className="rounded-xl border p-6"
                            >
                                <step.icon className="size-8" />
                                <h3 className="mt-4 font-semibold">
                                    {step.title}
                                </h3>
                                <p className="mt-2 text-sm text-muted-foreground">
                                    {step.description}
                                </p>
                            </div>
                        ))}
                    </section>

                    <section className="grid items-center gap-8 py-12 lg:grid-cols-2">
                        <div>
                            <h2 className="text-2xl font-semibold">
                                This is what a challenge looks like.
                            </h2>
                            <p className="mt-3 text-sm text-muted-foreground">
                                Every challenge gives you a starter script and a
                                real drone API. Loops, conditionals, and sensor
                                reads all work — because it's real code, running
                                in a sandboxed worker, driving a real physics
                                sim.
                            </p>
                        </div>
                        <pre className="overflow-x-auto rounded-xl bg-slate-950 p-5 text-xs text-slate-200">
                            <code>{SAMPLE_CODE}</code>
                        </pre>
                    </section>

                    <section className="border-t py-12 text-center">
                        <h2 className="text-2xl font-semibold">
                            Ready to fly?
                        </h2>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Start with Drone Basics — no experience required.
                        </p>
                        <div className="mt-6">
                            <Link
                                href={auth.user ? coursesIndex() : register()}
                            >
                                <Button size="lg">
                                    {auth.user
                                        ? 'Browse courses'
                                        : 'Create your free account'}
                                </Button>
                            </Link>
                        </div>
                    </section>
                </main>
            </div>
        </>
    );
}
