import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { Skeleton } from '@/components/ui/skeleton';
import { show as showCourse } from '@/routes/courses';
import type { CourseSummary } from '@/types/simulator';

export function CourseCard({ course }: { course: CourseSummary }) {
    const hasChallenges = course.challengesCount > 0;

    return (
        <Link
            href={showCourse(course.slug)}
            className="block h-full rounded-xl outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
        >
            <Card className="h-full transition-shadow hover:shadow-md">
                <CardHeader>
                    <div className="flex items-center justify-between gap-2">
                        <CardTitle>{course.title}</CardTitle>
                        <Badge
                            variant="outline"
                            className="shrink-0 capitalize"
                        >
                            {course.difficulty}
                        </Badge>
                    </div>
                    {course.description && (
                        <CardDescription>{course.description}</CardDescription>
                    )}
                </CardHeader>
                <CardContent className="mt-auto space-y-2">
                    {hasChallenges ? (
                        <>
                            <p className="text-sm text-muted-foreground">
                                {course.completedCount}/{course.challengesCount}{' '}
                                challenges complete
                            </p>
                            <Progress
                                aria-hidden="true"
                                value={course.completedCount}
                                max={course.challengesCount}
                            />
                        </>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No challenges published yet
                        </p>
                    )}
                </CardContent>
            </Card>
        </Link>
    );
}

/** Layout-stable placeholder for deferred or polled course lists. */
export function CourseCardSkeleton() {
    return (
        <Card aria-hidden="true" className="h-full">
            <CardHeader>
                <div className="flex items-center justify-between gap-2">
                    <Skeleton className="h-5 w-2/5" />
                    <Skeleton className="h-5 w-16" />
                </div>
                <Skeleton className="h-4 w-full" />
                <Skeleton className="h-4 w-3/4" />
            </CardHeader>
            <CardContent className="mt-auto space-y-2">
                <Skeleton className="h-4 w-1/2" />
                <Skeleton className="h-2 w-full rounded-full" />
            </CardContent>
        </Card>
    );
}
