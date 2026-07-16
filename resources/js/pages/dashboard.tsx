import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Star, Trophy } from 'lucide-react';
import { CourseCard } from '@/components/course-card';
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
    courses: CourseSummary[];
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
                    <Card>
                        <CardHeader className="flex-row items-center gap-3 space-y-0">
                            <Trophy className="size-5 text-muted-foreground" />
                            <CardTitle className="text-base">
                                Challenges completed
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {stats.completed}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex-row items-center gap-3 space-y-0">
                            <Star className="size-5 text-muted-foreground" />
                            <CardTitle className="text-base">
                                Stars earned
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">
                            {stats.stars}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="space-y-0">
                            <CardTitle className="text-base">
                                Continue where you left off
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {continueChallenge ? (
                                <Link
                                    href={showChallenge([
                                        continueChallenge.courseSlug,
                                        continueChallenge.challengeSlug,
                                    ])}
                                >
                                    <Button size="sm">
                                        {continueChallenge.challengeTitle}{' '}
                                        <ArrowRight />
                                    </Button>
                                </Link>
                            ) : (
                                <Link href={coursesIndex()}>
                                    <Button size="sm">
                                        Browse courses <ArrowRight />
                                    </Button>
                                </Link>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <div>
                    <h2 className="mb-3 text-lg font-semibold">Courses</h2>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {courses.map((course) => (
                            <CourseCard key={course.slug} course={course} />
                        ))}
                    </div>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
