import { Head } from '@inertiajs/react';
import { CourseCard } from '@/components/course-card';
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
                <div>
                    <h1 className="text-2xl font-semibold">Courses</h1>
                    <p className="text-sm text-muted-foreground">
                        Learn drone programming through hands-on simulator
                        challenges.
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {courses.map((course) => (
                        <CourseCard key={course.slug} course={course} />
                    ))}
                </div>
            </div>
        </>
    );
}

CoursesIndex.layout = {
    breadcrumbs: [{ title: 'Courses', href: coursesIndex() }],
};
