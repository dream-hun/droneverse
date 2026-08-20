import { Head, Link, usePage } from '@inertiajs/react';
import { CardGrid } from '@/components/card-grid';
import { ChallengeRow, ChallengeRowSkeleton } from '@/components/challenge-row';
import {
    MarketingPageHeader,
    MarketingShell,
    PRIMARY_ACTION,
    SECONDARY_ACTION,
    SectionLabel,
} from '@/components/marketing/marketing-shell';
import { QuizRow } from '@/components/quiz-row';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import {
    isPaidTier,
    missionCostSummary,
    planLabel,
    tierLabel,
} from '@/lib/catalog';
import { pricing, register } from '@/routes';
import { docs as courseDocs } from '@/routes/courses';
import type { QuizSummary } from '@/types/quiz';
import type { ChallengeSummary, CourseDetail } from '@/types/simulator';

type CourseShowProps = {
    course: CourseDetail;
    /** Whether a written guide exists for this course; false hides the link. */
    hasDocs: boolean;
    /** `undefined` while deferred; `[]` means there are genuinely none. */
    challenges: ChallengeSummary[] | undefined;
    /** `undefined` while deferred; `[]` means there are genuinely none. */
    quizzes: QuizSummary[] | undefined;
};

/**
 * One course, as the public catalogue presents it.
 *
 * Open to everyone and dressed like the rest of the public site, because this
 * is where a visitor decides whether the subscription is worth it: every
 * mission is listed whatever plan the viewer is on, and the locked ones name
 * the plan that includes them instead of being hidden. The gate that matters
 * is on the mission route, not on this page.
 */
export default function CourseShow({
    course,
    hasDocs,
    challenges,
    quizzes,
}: CourseShowProps) {
    const { auth } = usePage().props;
    const signedIn = Boolean(auth.user);

    const paidCourse = isPaidTier(course.requiredPlan);

    /*
     * The plan the shut missions belong to, or undefined when this viewer has
     * none. Read off the missions rather than off the course: a Starter course
     * can hold Pro missions, and the upgrade band has to name the plan that
     * actually opens the rows above it.
     */
    const lockedPlan = challenges?.find(
        (challenge) => challenge.locked,
    )?.requiredPlan;

    const cost =
        missionCostSummary(
            challenges?.map((challenge) => challenge.requiredPlan) ?? [],
        ) ??
        (paidCourse
            ? `Included with ${tierLabel(course.requiredPlan)}`
            : 'Free to fly');

    return (
        <MarketingShell current="courses">
            <Head title={course.title}>
                <meta name="description" content={course.description} />
            </Head>

            <MarketingPageHeader
                eyebrow="Course"
                title={course.title}
                lede={course.description}
                meta={
                    <>
                        <span>{course.difficulty}</span>
                        {challenges && (
                            <span>
                                {challenges.length}{' '}
                                {challenges.length === 1
                                    ? 'mission'
                                    : 'missions'}
                            </span>
                        )}
                        <span className="text-primary">{cost}</span>
                    </>
                }
                actions={
                    <>
                        {/*
                         * Only when there is something to read. A course is a
                         * row and can exist long before its guide is written,
                         * and a link that 404s teaches a pilot to distrust
                         * the rest of the page.
                         */}
                        {hasDocs && (
                            <Link
                                href={courseDocs(course.slug)}
                                className={PRIMARY_ACTION}
                            >
                                Read the guide
                            </Link>
                        )}

                        {!signedIn && (
                            <Link
                                href={register()}
                                className={SECONDARY_ACTION}
                            >
                                Create a free account →
                            </Link>
                        )}

                        {/*
                         * No upgrade button here: whether this viewer has
                         * anything shut to them depends on the mission list,
                         * which is still in flight while this header renders.
                         * The band at the foot of the page asks once the
                         * answer is known, and each locked row asks for
                         * itself.
                         */}
                    </>
                }
            />

            <div className="mx-auto max-w-7xl space-y-24 px-6 py-24">
                <section>
                    <SectionLabel>Missions</SectionLabel>

                    <CardGrid
                        layout="stack"
                        listClassName="gap-1"
                        items={challenges}
                        itemKey={(challenge) => challenge.slug}
                        label={`Missions in ${course.title}`}
                        skeleton={<ChallengeRowSkeleton />}
                        skeletonItems={4}
                        empty={
                            <EmptyState className="rounded-none border-solid border-border bg-white/[0.02]">
                                <EmptyStateTitle className="font-mono text-xs tracking-widest text-primary uppercase">
                                    No missions yet
                                </EmptyStateTitle>
                                <EmptyStateDescription>
                                    This course doesn't have any published
                                    missions yet. Check back soon.
                                </EmptyStateDescription>
                            </EmptyState>
                        }
                    >
                        {(challenge, index) => (
                            <ChallengeRow
                                challenge={challenge}
                                courseSlug={course.slug}
                                index={index}
                            />
                        )}
                    </CardGrid>
                </section>

                {/*
                 * Rendered only once there is something to render. Most
                 * courses carry no quiz, and this section is deferred like
                 * the missions above it — a skeleton or an empty state here
                 * would put "no quizzes yet" under every course in the
                 * catalog, which is noise rather than information. The
                 * missions are what this page is for; the quiz is an extra,
                 * and it appears when it exists.
                 */}
                {quizzes !== undefined && quizzes.length > 0 && (
                    <section>
                        <SectionLabel>Knowledge check</SectionLabel>

                        <ul
                            aria-label={`Quizzes in ${course.title}`}
                            className="grid gap-1"
                        >
                            {quizzes.map((quiz) => (
                                <li key={quiz.slug}>
                                    <QuizRow
                                        quiz={quiz}
                                        courseSlug={course.slug}
                                    />
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {/*
                 * Only when this viewer actually has something shut to them.
                 * A subscriber reading their own course does not need to be
                 * sold the plan they are already paying for.
                 */}
                {lockedPlan !== undefined && (
                    <section className="border border-primary bg-primary/5 p-8 md:p-12">
                        <h2 className="mb-4 text-3xl font-bold tracking-tighter uppercase">
                            Fly the locked missions
                        </h2>
                        <p className="mb-8 max-w-xl leading-relaxed text-muted-foreground">
                            The briefings above are yours to read either way.
                            Flying the ones marked {planLabel(lockedPlan)} takes
                            a subscription — it also adds Python, your pick of
                            airframe, and analytics on every run.
                        </p>
                        <div className="flex flex-wrap gap-4">
                            <Link href={pricing()} className={PRIMARY_ACTION}>
                                See plans
                            </Link>
                            {!signedIn && (
                                <Link
                                    href={register()}
                                    className={SECONDARY_ACTION}
                                >
                                    Create a free account →
                                </Link>
                            )}
                        </div>
                    </section>
                )}
            </div>
        </MarketingShell>
    );
}
