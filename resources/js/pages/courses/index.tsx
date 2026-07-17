import { Head } from '@inertiajs/react';
import { Compass } from 'lucide-react';
import { CourseCard } from '@/components/course-card';
import { PageHeader } from '@/components/page-header';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateIcon,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import { index as coursesIndex } from '@/routes/courses';
import type { CourseSummary } from '@/types/simulator';

type CoursesIndexProps = {
    courses: CourseSummary[];
};

export default function CoursesIndex({ courses }: CoursesIndexProps) {
    return (
        <>
            <Head title="Courses" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Courses"
                    description="Learn drone programming through hands-on simulator challenges."
                />

                {courses.length > 0 ? (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {courses.map((course) => (
                            <CourseCard key={course.slug} course={course} />
                        ))}
                    </div>
                ) : (
                    <EmptyState>
                        <EmptyStateIcon>
                            <Compass />
                        </EmptyStateIcon>
                        <EmptyStateTitle>
                            No courses available yet
                        </EmptyStateTitle>
                        <EmptyStateDescription>
                            New flight courses are on the way. Check back soon.
                        </EmptyStateDescription>
                    </EmptyState>
                )}
            </div>
        </>
    );
}

CoursesIndex.layout = {
    breadcrumbs: [{ title: 'Courses', href: coursesIndex() }],
};
