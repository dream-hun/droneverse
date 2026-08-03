import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Star, Trophy } from 'lucide-react';
import { CardGrid } from '@/components/card-grid';
import {
    CourseCard,
    CourseCardSkeleton,
    NoCoursesEmptyState,
} from '@/components/course-card';
import { StatCard } from '@/components/stat-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';
import { show as showChallenge } from '@/routes/challenges';
import { index as coursesIndex } from '@/routes/courses';
import type { CourseSummary } from '@/types/simulator';

type ContinueChallenge = {
    courseSlug: string;
    challengeSlug: string;
    challengeTitle: string;
};

type DashboardProps = {
    /** `undefined` while deferred; `[]` means there are genuinely none. */
    courses: CourseSummary[] | undefined;
    continue: ContinueChallenge | null;
    stats: { completed: number; stars: number };
};

export default function Dashboard({
    courses,
    continue: continueChallenge,
    stats,
}: DashboardProps) {
    return (
        <>
            <Head title="Dashboard" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard
                        label="Challenges completed"
                        value={stats.completed}
                        icon={Trophy}
                    />
                    <StatCard
                        label="Stars earned"
                        value={stats.stars}
                        icon={Star}
                    />
                    <Card>
                        <CardHeader className="space-y-0">
                            <CardTitle className="text-base">
                                Continue where you left off
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {continueChallenge ? (
                                <Button asChild size="sm">
                                    <Link
                                        href={showChallenge([
                                            continueChallenge.courseSlug,
                                            continueChallenge.challengeSlug,
                                        ])}
                                    >
                                        {continueChallenge.challengeTitle}{' '}
                                        <ArrowRight />
                                    </Link>
                                </Button>
                            ) : (
                                <Button asChild size="sm">
                                    <Link href={coursesIndex()}>
                                        Browse courses <ArrowRight />
                                    </Link>
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <div>
                    <h2 className="mb-3 text-lg font-semibold">Courses</h2>
                    <CardGrid
                        items={courses}
                        itemKey={(course) => course.slug}
                        label="Courses"
                        skeleton={<CourseCardSkeleton />}
                        skeletonItems={3}
                        empty={<NoCoursesEmptyState />}
                    >
                        {(course) => <CourseCard course={course} />}
                    </CardGrid>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
