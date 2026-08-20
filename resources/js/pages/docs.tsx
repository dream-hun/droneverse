import { Head, Link, usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { CodeExample } from '@/components/docs/code-example';
import { CommandList } from '@/components/docs/command-reference';
import { Prose } from '@/components/docs/prose';
import {
    MarketingPageHeader,
    MarketingShell,
    PRIMARY_ACTION,
    SECONDARY_ACTION,
} from '@/components/marketing/marketing-shell';
import { StarRating } from '@/components/star-rating';
import { dashboard, register } from '@/routes';
import { index as coursesIndex, show as showCourse } from '@/routes/courses';
import type { DroneManual, ManualCourse } from '@/types/docs';

type DocsProps = {
    manual: DroneManual;
};

type ManualSection = {
    /** Doubles as the anchor the contents list and any outside link point at. */
    id: string;
    title: string;
    description?: string;
    body: React.ReactNode;
};

/**
 * Which course a group of examples or warnings came from.
 *
 * A link when the course is published and a plain name when it is not — a
 * guide can be authored before its course is seeded, and naming it without
 * offering a way in is better than offering a 404.
 */
function FromCourse({ course }: { course: ManualCourse }) {
    return (
        <h3 className="font-mono text-xs tracking-widest text-muted-foreground uppercase">
            From{' '}
            {course.published ? (
                <Link
                    href={showCourse(course.slug)}
                    className="text-primary transition-colors hover:underline"
                >
                    {course.title}
                </Link>
            ) : (
                <span className="text-foreground">{course.title}</span>
            )}
        </h3>
    );
}

/**
 * The reference manual.
 *
 * Public, complete, and the only page on the site that is both: every command
 * the simulator answers rather than the handful one course leans on, every
 * worked example anybody has written, and the scoring policy — which until now
 * a pilot could only learn by losing points to it.
 *
 * The course guides are not replaced by this and are not meant to be. They
 * argue one course's case in its own order; this is what you open when you
 * know what you want and need the signature, or when you are deciding whether
 * to write any of this at all.
 *
 * Sections are built as an array and mapped twice, so the numbered contents
 * list and the numbered headings cannot fall out of step.
 */
export default function Docs({ manual }: DocsProps) {
    const { auth } = usePage().props;
    const signedIn = Boolean(auth.user);

    const commandCount = manual.commandGroups.reduce(
        (total, group) => total + group.commands.length,
        0,
    );

    const exampleCount = manual.examples.reduce(
        (total, group) => total + group.items.length,
        0,
    );

    const sections: ManualSection[] = [
        {
            id: 'writing-a-program',
            title: 'Writing a program',
            description:
                'True of every mission in every course, so it is worth reading once.',
            body: (
                <dl className="grid gap-1">
                    {manual.concepts.map((concept) => (
                        <div
                            key={concept.title}
                            className="border border-border bg-white/[0.02] p-6"
                        >
                            <dt className="font-mono text-xs tracking-widest text-primary uppercase">
                                {concept.title}
                            </dt>
                            <dd className="mt-3 text-pretty text-muted-foreground">
                                <Prose text={concept.body} />
                            </dd>
                        </div>
                    ))}
                </dl>
            ),
        },
        ...manual.commandGroups.map((group): ManualSection => ({
            id: `commands-${group.key}`,
            title: group.label,
            description: `${group.commands.length} ${group.commands.length === 1 ? 'command' : 'commands'}. Every one of them is awaited.`,
            body: <CommandList commands={group.commands} />,
        })),
        {
            id: 'examples',
            title: 'Worked examples',
            description:
                'Complete programs, every one of them written for the documentation rather than lifted from a mission. Copy any of them into an editor and hit Run.',
            body: (
                <div className="space-y-12">
                    {manual.examples.map((group) => (
                        <section
                            key={group.course.slug}
                            className="space-y-6"
                            aria-label={`Examples from ${group.course.title}`}
                        >
                            <FromCourse course={group.course} />

                            <div className="space-y-8">
                                {group.items.map((example) => (
                                    <CodeExample
                                        key={example.slug}
                                        example={example}
                                    />
                                ))}
                            </div>
                        </section>
                    ))}
                </div>
            ),
        },
        {
            id: 'scoring',
            title: 'How a run is scored',
            description: `Graded the moment the drone stops flying, out of ${manual.scoring.total} points before the mission's own maximum scales it.`,
            body: (
                <div className="space-y-8">
                    <ul className="grid gap-1">
                        {manual.scoring.weights.map((weight) => (
                            <li
                                key={weight.label}
                                className="flex gap-5 border border-border bg-white/[0.02] p-6"
                            >
                                <span className="font-mono text-3xl font-bold tracking-tighter text-primary tabular-nums">
                                    {weight.points}
                                </span>
                                <div>
                                    <h4 className="font-mono text-xs tracking-widest text-foreground uppercase">
                                        {weight.label}
                                    </h4>
                                    <p className="mt-2 text-pretty">
                                        {weight.note}
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ul>

                    <div className="space-y-3 text-pretty">
                        <p>
                            Every collision costs a further{' '}
                            <strong className="font-semibold text-foreground">
                                {manual.scoring.collisionPenalty} points
                            </strong>{' '}
                            on missions that check for them.
                        </p>
                        <p>{manual.scoring.completion}</p>
                        <p>{manual.scoring.scaling}</p>
                    </div>

                    <div className="space-y-4">
                        <h4 className="font-mono text-xs tracking-widest text-primary uppercase">
                            Earning the stars
                        </h4>
                        <ol className="grid gap-1">
                            {manual.scoring.stars.map((rule, index) => (
                                <li
                                    key={rule}
                                    className="flex items-center gap-4 border border-border bg-white/[0.02] px-6 py-4"
                                >
                                    <StarRating
                                        value={index + 1}
                                        max={manual.scoring.stars.length}
                                        size="sm"
                                        label={`${index + 1} of ${manual.scoring.stars.length} stars`}
                                    />
                                    <span className="text-pretty">{rule}</span>
                                </li>
                            ))}
                        </ol>
                    </div>
                </div>
            ),
        },
        {
            id: 'pitfalls',
            title: 'What usually goes wrong',
            description:
                'The mistakes that cost the most runs, gathered from every course that warns about one.',
            body: (
                <div className="space-y-12">
                    {manual.pitfalls.map((group) => (
                        <section
                            key={group.course.slug}
                            className="space-y-6"
                            aria-label={`Pitfalls from ${group.course.title}`}
                        >
                            <FromCourse course={group.course} />

                            <ul className="grid gap-1 sm:grid-cols-2">
                                {group.items.map((pitfall) => (
                                    <li
                                        key={pitfall.title}
                                        className="flex gap-3 border border-border bg-white/[0.02] p-4"
                                    >
                                        <TriangleAlert
                                            aria-hidden
                                            className="mt-0.5 size-4 shrink-0 text-primary"
                                        />
                                        <div className="space-y-1">
                                            <h4 className="font-mono text-xs tracking-widest text-foreground uppercase">
                                                {pitfall.title}
                                            </h4>
                                            <p className="text-pretty text-muted-foreground">
                                                <Prose text={pitfall.body} />
                                            </p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))}
                </div>
            ),
        },
    ];

    return (
        <MarketingShell current="docs">
            <Head title="Documentation">
                <meta name="description" content={manual.tagline} />
            </Head>

            <MarketingPageHeader
                eyebrow="Documentation"
                title="The flight manual"
                lede={manual.tagline}
                meta={
                    <>
                        <span>{commandCount} commands</span>
                        <span>{exampleCount} worked examples</span>
                        <span className="text-primary">
                            No account required
                        </span>
                    </>
                }
                actions={
                    <>
                        <Link
                            href={signedIn ? dashboard() : register()}
                            className={PRIMARY_ACTION}
                        >
                            {signedIn ? 'Go to dashboard' : 'Start flying free'}
                        </Link>
                        <Link
                            href={coursesIndex()}
                            className={SECONDARY_ACTION}
                        >
                            Browse courses →
                        </Link>
                    </>
                }
            />

            <div className="mx-auto grid max-w-7xl gap-12 px-6 py-16 lg:grid-cols-[16rem_1fr] lg:gap-16">
                <nav
                    aria-label="On this page"
                    className="lg:sticky lg:top-24 lg:self-start"
                >
                    <h2 className="font-mono text-xs tracking-widest text-primary uppercase">
                        On this page
                    </h2>
                    <ol className="mt-4 space-y-2">
                        {sections.map((section, index) => (
                            <li key={section.id} className="flex gap-3 text-sm">
                                <span className="font-mono text-xs text-muted-foreground tabular-nums">
                                    {String(index + 1).padStart(2, '0')}
                                </span>
                                <a
                                    href={`#${section.id}`}
                                    className="text-muted-foreground transition-colors hover:text-primary"
                                >
                                    {section.title}
                                </a>
                            </li>
                        ))}
                    </ol>
                </nav>

                <article className="max-w-3xl min-w-0 space-y-14">
                    <p className="text-lg leading-relaxed text-pretty text-foreground">
                        {manual.summary}
                    </p>

                    {sections.map((section, index) => (
                        <section
                            key={section.id}
                            id={section.id}
                            className="scroll-mt-24"
                        >
                            <h2 className="flex gap-4 text-lg font-bold tracking-tighter text-foreground uppercase">
                                <span className="font-mono text-sm text-primary tabular-nums">
                                    {String(index + 1).padStart(2, '0')}
                                </span>
                                {section.title}
                            </h2>

                            {section.description && (
                                <p className="mt-3 text-sm leading-relaxed text-pretty text-muted-foreground">
                                    {section.description}
                                </p>
                            )}

                            <div className="mt-6 text-sm leading-relaxed text-muted-foreground">
                                {section.body}
                            </div>
                        </section>
                    ))}
                </article>
            </div>
        </MarketingShell>
    );
}
