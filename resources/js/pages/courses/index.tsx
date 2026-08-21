import { Head, Link, usePage } from '@inertiajs/react';
import { Check, Lock } from 'lucide-react';
import { CardGrid } from '@/components/card-grid';
import {
    MarketingPageHeader,
    MarketingShell,
    PILL,
    PRIMARY_ACTION,
    SECONDARY_ACTION,
    SectionLabel,
} from '@/components/marketing/marketing-shell';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { completionPercent, courseCostSummary, planLabel } from '@/lib/catalog';
import { cn } from '@/lib/utils';
import { dashboard, pricing, register } from '@/routes';
import { show as showCourse } from '@/routes/courses';
import type { CourseSummary } from '@/types/simulator';

type CoursesIndexProps = {
    /** `undefined` while deferred; `[]` means there are genuinely none. */
    courses: CourseSummary[] | undefined;
};

/**
 * A total over the catalogue, or `undefined` while it is still deferred — so
 * the header renders no number rather than a confident zero.
 */
function sum(
    courses: CourseSummary[] | undefined,
    of: (course: CourseSummary) => number,
): number | undefined {
    return courses?.reduce((total, course) => total + of(course), 0);
}

const LEDE =
    'Every course is a stack of missions you fly in the browser: write JavaScript, run it against a physics-simulated quadcopter, and get scored the moment it lands.';

/**
 * What flying this course costs, as a pill in the corner of its card.
 *
 * Read off `missionPlan` rather than off the course's own tier, because they
 * are different facts and only one of them is about money: Precision Flight is
 * a Starter course every mission of which is Pro, and a pill badged from
 * `requiredPlan` would label it free.
 *
 * The lock is the viewer's half of it and appears only when they have
 * something shut to them. A subscriber sees the tier they are paying for
 * stated plainly, not as a wall.
 */
function TierPill({ course, shut }: { course: CourseSummary; shut: boolean }) {
    if (course.missionPlan === null) {
        return (
            <span className={cn(PILL, 'border-border text-primary')}>Free</span>
        );
    }

    return (
        <span
            className={cn(
                PILL,
                shut
                    ? 'border-primary text-primary'
                    : 'border-border text-muted-foreground',
            )}
        >
            {shut ? (
                <Lock aria-hidden className="size-3" />
            ) : (
                <Check aria-hidden className="size-3" />
            )}
            {planLabel(course.missionPlan)}
        </span>
    );
}

/**
 * One course in the catalogue.
 *
 * The whole panel is the link, so nothing inside it may be one — a locked card
 * says which plan includes it and leaves the way to buy that plan to the band
 * underneath, because a link nested in a link is markup browsers resolve by
 * guessing.
 */
function CourseCard({
    course,
    signedIn,
    shut,
}: {
    course: CourseSummary;
    signedIn: boolean;
    /** The viewer cannot fly what this course charges for. */
    shut: boolean;
}) {
    const percent = completionPercent(
        course.completedCount,
        course.challengesCount,
    );

    return (
        <Link
            href={showCourse(course.slug)}
            className="flex h-full flex-col border border-border bg-white/[0.02] p-6 transition-colors outline-none hover:bg-white/[0.04] focus-visible:border-primary md:p-8"
        >
            <div className="flex items-start justify-between gap-4">
                <span className="font-mono text-xs tracking-widest text-muted-foreground uppercase">
                    {course.difficulty}
                </span>
                <TierPill course={course} shut={shut} />
            </div>

            <h3 className="mt-4 text-xl font-bold tracking-tight uppercase">
                {course.title}
            </h3>

            {course.description && (
                <p className="mt-4 flex-1 text-sm leading-relaxed text-muted-foreground">
                    {course.description}
                </p>
            )}

            <p className="mt-6 font-mono text-xs tracking-widest text-primary uppercase">
                {courseCostSummary(
                    course.freeChallengesCount,
                    course.challengesCount,
                    course.missionPlan,
                )}
            </p>

            {/*
             * Progress is the pilot's own, so it is only drawn for one. A
             * guest's counters are all zero — an empty bar on a marketing page
             * would read as a course nobody has finished rather than as a
             * course nobody is signed in for.
             */}
            {signedIn && course.challengesCount > 0 && (
                <div className="mt-4">
                    <p className="flex justify-between font-mono text-xs tracking-widest text-muted-foreground uppercase">
                        <span>Flown</span>
                        <span>
                            {course.completedCount}/{course.challengesCount}
                        </span>
                    </p>
                    <div aria-hidden className="mt-2 h-1 w-full bg-border">
                        <div
                            className="h-full bg-primary"
                            style={{ width: `${percent}%` }}
                        />
                    </div>
                </div>
            )}
        </Link>
    );
}

/** Layout-stable placeholder for the deferred catalogue. */
function CourseCardSkeleton() {
    return (
        <div
            aria-hidden
            className="flex h-full flex-col gap-4 border border-border bg-white/[0.02] p-6 md:p-8"
        >
            <Skeleton className="h-3 w-24" />
            <Skeleton className="h-6 w-2/3" />
            <Skeleton className="h-4 w-full" />
            <Skeleton className="h-4 w-4/5" />
            <Skeleton className="mt-2 h-3 w-20" />
        </div>
    );
}

function NothingPublished({ title, note }: { title: string; note: string }) {
    return (
        <EmptyState className="rounded-none border-solid border-border bg-white/[0.02]">
            <EmptyStateTitle className="font-mono text-xs tracking-widest text-primary uppercase">
                {title}
            </EmptyStateTitle>
            <EmptyStateDescription>{note}</EmptyStateDescription>
        </EmptyState>
    );
}

/**
 * The public course catalogue.
 *
 * A front page rather than a screen of the app: it is what the marketing
 * header's "Courses" link opens, it is indexed, and most of the people reading
 * it have no account. So it wears the same chrome as the landing and pricing
 * pages, and it is organised around the one question a visitor arrives with —
 * what can I fly without paying, and what does paying add.
 *
 * The free band comes first for that reason, and the paid band always names
 * the plan that includes it rather than only marking it shut.
 */
export default function CoursesIndex({ courses }: CoursesIndexProps) {
    const { auth } = usePage().props;
    const signedIn = Boolean(auth.user);

    /*
     * `undefined` while the catalogue is deferred, which is what keeps the two
     * bands showing placeholders instead of "no courses yet" on the way to it.
     *
     * The split is on whether there is anything here to fly for nothing, not
     * on the course's own tier: a Starter course whose every mission is Pro
     * belongs under the paid heading, whatever its row says.
     */
    const free = courses?.filter((course) => course.freeChallengesCount > 0);
    const paid = courses?.filter((course) => course.freeChallengesCount === 0);

    const missionCount = sum(courses, (course) => course.challengesCount);
    const freeMissionCount = sum(
        courses,
        (course) => course.freeChallengesCount,
    );

    /*
     * A rendering hint, as `auth.features` is: every paid course in the
     * catalogue is Pro, and every paid plan covers Pro, so a viewer on any of
     * them has nothing shut to them here. `locked` is carried alongside it for
     * the tier a course's own row claims, which is the only case this misses.
     */
    const shutOut = (course: CourseSummary) =>
        course.locked || (course.missionPlan !== null && !auth.plan.isPaid);

    /** The plan the paid band is selling, once the catalogue says which. */
    const paidPlan = paid?.find(
        (course) => course.missionPlan !== null,
    )?.missionPlan;

    const showUpgrade = (paid?.length ?? 0) > 0 && !auth.plan.isPaid;

    return (
        <MarketingShell current="courses">
            <Head title="Courses">
                <meta name="description" content={LEDE} />
            </Head>

            <MarketingPageHeader
                eyebrow="Flight school"
                title="Every course, ground up"
                lede={LEDE}
                meta={
                    courses && (
                        <>
                            <span>
                                {courses.length}{' '}
                                {courses.length === 1 ? 'course' : 'courses'}
                            </span>
                            <span>{missionCount} missions</span>
                            <span className="text-primary">
                                {freeMissionCount} free to fly
                            </span>
                        </>
                    )
                }
                actions={
                    <>
                        <Link
                            href={signedIn ? dashboard() : register()}
                            className={PRIMARY_ACTION}
                        >
                            {signedIn
                                ? 'Go to dashboard'
                                : 'Start learning free'}
                        </Link>
                        {!auth.plan.isPaid && (
                            <Link href={pricing()} className={SECONDARY_ACTION}>
                                Compare plans →
                            </Link>
                        )}
                    </>
                }
            />

            <div className="mx-auto max-w-7xl space-y-24 px-6 py-24">
                <section>
                    <SectionLabel>Free to fly</SectionLabel>

                    <div className="mb-12 max-w-3xl">
                        <h2 className="mb-4 text-3xl font-bold tracking-tighter uppercase lg:text-4xl">
                            Start here. No card, no expiry.
                        </h2>
                        <p className="leading-relaxed text-muted-foreground">
                            Every course here has missions you can fly the
                            moment you make an account, as many times as you
                            like. Where a course carries paid missions too, its
                            card says how many.
                        </p>
                    </div>

                    <CardGrid
                        items={free}
                        itemKey={(course) => course.slug}
                        label="Free courses"
                        listClassName="gap-1"
                        skeleton={<CourseCardSkeleton />}
                        skeletonItems={3}
                        empty={
                            <NothingPublished
                                title="No free courses yet"
                                note="New flight courses are on the way. Check back soon."
                            />
                        }
                    >
                        {(course) => (
                            <CourseCard
                                course={course}
                                signedIn={signedIn}
                                shut={shutOut(course)}
                            />
                        )}
                    </CardGrid>
                </section>

                {/* Nothing paid published means nothing to sell, and a band
                    headed "Included with Pro" over an empty grid would be an
                    advertisement for content that does not exist. */}
                {(paid === undefined || paid.length > 0) && (
                    <section>
                        <SectionLabel>
                            {`Included with ${paidPlan ? planLabel(paidPlan) : 'Pro'}`}
                        </SectionLabel>

                        <div className="mb-12 max-w-3xl">
                            <h2 className="mb-4 text-3xl font-bold tracking-tighter uppercase lg:text-4xl">
                                The rest of the catalogue.
                            </h2>
                            <p className="leading-relaxed text-muted-foreground">
                                Nothing here is hidden from you: open any
                                course, read its guide, and see every mission it
                                holds. Flying them is what takes a subscription.
                            </p>
                        </div>

                        <CardGrid
                            items={paid}
                            itemKey={(course) => course.slug}
                            label="Courses included with a subscription"
                            listClassName="gap-1"
                            skeleton={<CourseCardSkeleton />}
                            skeletonItems={3}
                            empty={
                                <NothingPublished
                                    title="Nothing here yet"
                                    note="Subscriber courses are on the way."
                                />
                            }
                        >
                            {(course) => (
                                <CourseCard
                                    course={course}
                                    signedIn={signedIn}
                                    shut={shutOut(course)}
                                />
                            )}
                        </CardGrid>

                        {showUpgrade && (
                            <div className="mt-1 border border-primary bg-primary/5 p-8 md:p-12">
                                <h3 className="mb-4 text-3xl font-bold tracking-tighter uppercase">
                                    Unlock the whole catalogue
                                </h3>
                                <p className="mb-8 max-w-xl leading-relaxed text-muted-foreground">
                                    A subscription adds every course above, your
                                    pick of airframe on any mission, and
                                    analytics on every run you fly.
                                </p>
                                <div className="flex flex-wrap gap-4">
                                    <Link
                                        href={pricing()}
                                        className={PRIMARY_ACTION}
                                    >
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
                            </div>
                        )}
                    </section>
                )}
            </div>
        </MarketingShell>
    );
}
