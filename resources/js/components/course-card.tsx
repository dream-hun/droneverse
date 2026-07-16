import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { show as showCourse } from '@/routes/courses';
import type { CourseSummary } from '@/types/simulator';

export function CourseCard({ course }: { course: CourseSummary }) {
    return (
        <Link href={showCourse(course.slug)}>
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
                <CardContent>
                    <p className="text-sm text-muted-foreground">
                        {course.completedCount}/{course.challengesCount}{' '}
                        challenges complete
                    </p>
                </CardContent>
            </Card>
        </Link>
    );
}
