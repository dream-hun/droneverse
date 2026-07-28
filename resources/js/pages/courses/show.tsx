import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { CheckCircle2, Circle, Lock, PlayCircle, Route } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { PlanLockBadge, PlanUpgradeHint } from '@/components/plan-lock-badge';
import { StarRating } from '@/components/star-rating';
import { Badge } from '@/components/ui/badge';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateIcon,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import { show as showChallenge } from '@/routes/challenges';
import { index as coursesIndex, show as showCourse } from '@/routes/courses';
import type {
    ChallengeStatus,
    ChallengeSummary,
    CourseDetail,
} from '@/types/simulator';

type CourseShowProps = {
    course: CourseDetail;
    challenges: ChallengeSummary[];
};

const STATUS_ICON: Record<ChallengeStatus, typeof Circle> = {
    not_started: Circle,
    in_progress: PlayCircle,
    completed: CheckCircle2,
};

export default function CourseShow({ course, challenges }: CourseShowProps) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Courses', href: coursesIndex() },
            { title: course.title, href: showCourse(course.slug) },
        ],
    });

    return (
        <>
            <Head title={course.title} />

            <div className="space-y-6 p-4">
                <PageHeader
                    title={course.title}
                    description={course.description}
                    badge={
                        <Badge variant="outline" className="capitalize">
                            {course.difficulty}
                        </Badge>
                    }
                />

                {challenges.length === 0 ? (
                    <EmptyState>
                        <EmptyStateIcon>
                            <Route />
                        </EmptyStateIcon>
                        <EmptyStateTitle>No challenges yet</EmptyStateTitle>
                        <EmptyStateDescription>
                            This course doesn't have any published challenges
                            yet. Check back soon.
                        </EmptyStateDescription>
                    </EmptyState>
                ) : (
                    <div className="space-y-3">
                        {challenges.map((challenge, index) => {
                            const Icon = challenge.locked
                                ? Lock
                                : STATUS_ICON[challenge.status];

                            const card = (
                                <Card
                                    className={
                                        challenge.locked
                                            ? 'border-dashed'
                                            : 'transition-shadow hover:shadow-md'
                                    }
                                >
                                    <CardHeader className="flex-row items-center justify-between space-y-0">
                                        <div className="flex items-center gap-3">
                                            <Icon
                                                aria-hidden="true"
                                                className="size-5 shrink-0 text-muted-foreground"
                                            />
                                            <div>
                                                <CardTitle
                                                    className={`text-base ${challenge.locked ? 'text-muted-foreground' : ''}`}
                                                >
                                                    {index + 1}.{' '}
                                                    {challenge.title}
                                                </CardTitle>
                                                {challenge.locked ? (
                                                    <PlanUpgradeHint
                                                        plan={
                                                            challenge.requiredPlan
                                                        }
                                                    />
                                                ) : (
                                                    <p className="text-xs text-muted-foreground capitalize">
                                                        {challenge.difficulty}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {challenge.locked && (
                                                <PlanLockBadge
                                                    asLink
                                                    plan={
                                                        challenge.requiredPlan
                                                    }
                                                />
                                            )}
                                            {challenge.stars > 0 && (
                                                <StarRating
                                                    value={challenge.stars}
                                                />
                                            )}
                                            {challenge.bestScore > 0 && (
                                                <Badge variant="outline">
                                                    {challenge.bestScore}
                                                </Badge>
                                            )}
                                        </div>
                                    </CardHeader>
                                </Card>
                            );

                            // A locked mission is shown in full but is not a
                            // link: the route would turn the pilot away, and a
                            // link that 403s reads as a bug rather than a
                            // paywall.
                            if (challenge.locked) {
                                return <div key={challenge.slug}>{card}</div>;
                            }

                            return (
                                <Link
                                    key={challenge.slug}
                                    href={showChallenge([
                                        course.slug,
                                        challenge.slug,
                                    ])}
                                    className="block rounded-xl outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                >
                                    {card}
                                </Link>
                            );
                        })}
                    </div>
                )}
            </div>
        </>
    );
}
