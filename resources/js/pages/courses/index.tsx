import { Head } from '@inertiajs/react';
import { CardGrid } from '@/components/card-grid';
import {
    CourseCard,
    CourseCardSkeleton,
    NoCoursesEmptyState,
} from '@/components/course-card';
import { PageHeader } from '@/components/page-header';
import { index as coursesIndex } from '@/routes/courses';
import type { CourseSummary } from '@/types/simulator';

type CoursesIndexProps = {
    /** `undefined` while deferred; `[]` means there are genuinely none. */
    courses: CourseSummary[] | undefined;
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

                <CardGrid
                    items={courses}
                    itemKey={(course) => course.slug}
                    label="Courses"
                    skeleton={<CourseCardSkeleton />}
                    empty={<NoCoursesEmptyState />}
                >
                    {(course) => <CourseCard course={course} />}
                </CardGrid>
            </div>
        </>
    );
}

CoursesIndex.layout = {
    breadcrumbs: [{ title: 'Courses', href: coursesIndex() }],
};
