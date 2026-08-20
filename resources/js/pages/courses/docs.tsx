import { Head, Link } from '@inertiajs/react';
import { Lock, TriangleAlert } from 'lucide-react';
import { CodeExample } from '@/components/docs/code-example';
import { CommandReference } from '@/components/docs/command-reference';
import { Prose } from '@/components/docs/prose';
import {
    MarketingPageHeader,
    MarketingShell,
    PRIMARY_ACTION,
    SECONDARY_ACTION,
} from '@/components/marketing/marketing-shell';
import { Skeleton } from '@/components/ui/skeleton';
import { tierLabel } from '@/lib/catalog';
import { cn } from '@/lib/utils';
import { docs } from '@/routes';
import { show as showChallenge } from '@/routes/challenges';
import { show as showCourse } from '@/routes/courses';
import type { CourseDocumentation, DocumentedMission } from '@/types/docs';
import type { CourseDetail } from '@/types/simulator';

type CourseDocsProps = {
    course: CourseDetail;
    documentation: CourseDocumentation;
    /** `undefined` while deferred; `[]` means there are genuinely none. */
    missions: DocumentedMission[] | undefined;
};

type GuideSection = {
    /** Doubles as the anchor the contents list and any outside link point at. */
    id: string;
    title: string;
    description: string;
    body: React.ReactNode;
};

/**
 * One mission in the index.
 *
 * Locked missions are listed but not linked, which is the rule
 * {@link ChallengeRow} already follows on the course page: the route would
 * turn the pilot away, and a link that 403s reads as a bug rather than as the
 * paywall it is. The lock is marked but not argued with — the course page is
 * where a tier is sold, and this is a table of contents.
 */
function MissionLink({
    courseSlug,
    mission,
    index,
}: {
    courseSlug: string;
    mission: DocumentedMission;
    index: number;
}) {
    const body = (
        <>
            <span className="font-mono text-xs text-muted-foreground tabular-nums">
                {String(index + 1).padStart(2, '0')}
            </span>
            {mission.title}
        </>
    );

    const className =
        'flex items-center gap-3 border border-border bg-white/[0.02] px-4 py-3 text-sm';

    if (mission.locked) {
        return (
            <div className={cn(className, 'text-muted-foreground')}>
                {body}
                <Lock aria-hidden className="ml-auto size-3.5 shrink-0" />
            </div>
        );
    }

    return (
        <Link
            href={showChallenge([courseSlug, mission.slug])}
            className={cn(
                className,
                'transition-colors outline-none hover:bg-white/[0.04] hover:text-primary focus-visible:ring-[3px] focus-visible:ring-ring/50',
            )}
        >
            {body}
        </Link>
    );
}

/**
 * The written guide behind a course.
 *
 * A public page in the public chrome, for the same reason the course page it
 * hangs off is: this is reference material a visitor is expected to read
 * before they have an account, and half of what makes the subscription worth
 * buying is being able to see what the missions ask for.
 *
 * Read top to bottom it is one argument: here is what you will be able to do,
 * here are the commands that do it, here is each one used in anger, and here
 * is what goes wrong. The missions are last on purpose — this page is what a
 * pilot reads before flying one and comes back to when stuck on one, so the
 * links out of it belong at the end rather than competing with the reference.
 *
 * Sections are built as an array and mapped twice, so the numbered contents
 * list and the numbered headings cannot fall out of step; a guide whose "03"
 * in the sidebar lands on section four is worse than no sidebar.
 */
export default function CourseDocs({
    course,
    documentation,
    missions,
}: CourseDocsProps) {
    const sections: GuideSection[] = [
        {
            id: 'objectives',
            title: "What you'll be able to do",
            description:
                'By the end of this course, without looking anything up.',
            body: (
                <ul className="space-y-2">
                    {documentation.objectives.map((objective) => (
                        <li
                            key={objective}
                            className="relative pl-5 text-pretty before:absolute before:top-1.5 before:left-0 before:size-1.5 before:bg-primary before:content-['']"
                        >
                            <Prose text={objective} />
                        </li>
                    ))}
                </ul>
            ),
        },
        {
            id: 'commands',
            title: "Commands you'll use",
            description:
                'Every one of these is awaited: the promise settles once the physics loop has actually flown the manoeuvre, not when the command is issued.',
            body: (
                <div className="space-y-6">
                    <CommandReference groups={documentation.commandGroups} />

                    <p className="text-pretty">
                        These are the ones this course is built on. The{' '}
                        <Link
                            href={docs()}
                            className="text-primary underline underline-offset-4"
                        >
                            full reference
                        </Link>{' '}
                        describes every command the drone answers, whichever
                        course teaches it.
                    </p>
                </div>
            ),
        },
        {
            id: 'examples',
            title: 'Worked examples',
            description:
                'Complete programs. Copy one into any mission editor in this course and hit Run — they are written to fly on their own, not to solve a particular mission.',
            body: (
                <div className="space-y-8">
                    {documentation.examples.map((example) => (
                        <CodeExample key={example.slug} example={example} />
                    ))}
                </div>
            ),
        },
        ...(documentation.pitfalls.length > 0
            ? [
                  {
                      id: 'pitfalls',
                      title: 'What usually goes wrong',
                      description:
                          'The mistakes that cost the most runs on this course.',
                      body: (
                          <ul className="grid gap-1 sm:grid-cols-2">
                              {documentation.pitfalls.map((pitfall) => (
                                  <li
                                      key={pitfall.title}
                                      className="flex gap-3 border border-border bg-white/[0.02] p-4"
                                  >
                                      <TriangleAlert
                                          aria-hidden
                                          className="mt-0.5 size-4 shrink-0 text-primary"
                                      />
                                      <div className="space-y-1">
                                          <h3 className="font-mono text-xs tracking-widest text-foreground uppercase">
                                              {pitfall.title}
                                          </h3>
                                          <p className="text-pretty text-muted-foreground">
                                              <Prose text={pitfall.body} />
                                          </p>
                                      </div>
                                  </li>
                              ))}
                          </ul>
                      ),
                  },
              ]
            : []),
        {
            id: 'missions',
            title: 'Missions in this course',
            description: 'Where the above gets flown.',
            body:
                missions === undefined ? (
                    <ul aria-hidden className="grid gap-1 sm:grid-cols-2">
                        {Array.from({ length: 4 }, (_, index) => (
                            <li key={index}>
                                <Skeleton className="h-12 w-full" />
                            </li>
                        ))}
                    </ul>
                ) : (
                    <ul
                        aria-label={`Missions in ${course.title}`}
                        className="grid gap-1 sm:grid-cols-2"
                    >
                        {missions.map((mission, index) => (
                            <li key={mission.slug}>
                                <MissionLink
                                    courseSlug={course.slug}
                                    mission={mission}
                                    index={index}
                                />
                            </li>
                        ))}
                    </ul>
                ),
        },
    ];

    return (
        <MarketingShell current="courses">
            <Head title={`${course.title} documentation`}>
                <meta name="description" content={documentation.tagline} />
            </Head>

            <MarketingPageHeader
                eyebrow="Documentation"
                title={`${course.title} guide`}
                lede={documentation.tagline}
                meta={
                    <>
                        <span>{course.difficulty}</span>
                        <span className="text-primary">
                            {tierLabel(course.requiredPlan)}
                        </span>
                    </>
                }
                actions={
                    <>
                        <Link
                            href={showCourse(course.slug)}
                            className={PRIMARY_ACTION}
                        >
                            Go to the missions
                        </Link>
                        {/*
                         * The manual rather than the catalog: "Courses" is
                         * already in the header on every page, and a pilot
                         * who has run out of what this course documents
                         * wants the command they have not been taught yet.
                         */}
                        <Link href={docs()} className={SECONDARY_ACTION}>
                            Full reference →
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
                        {documentation.summary}
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

                            <p className="mt-3 text-sm leading-relaxed text-pretty text-muted-foreground">
                                {section.description}
                            </p>

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
