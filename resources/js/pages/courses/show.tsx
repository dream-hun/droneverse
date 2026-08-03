import { Head, setLayoutProps } from '@inertiajs/react';
import { Route } from 'lucide-react';
import { CardGrid } from '@/components/card-grid';
import { ChallengeRow, ChallengeRowSkeleton } from '@/components/challenge-row';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateIcon,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import { index as coursesIndex, show as showCourse } from '@/routes/courses';
import type { ChallengeSummary, CourseDetail } from '@/types/simulator';

type CourseShowProps = {
    course: CourseDetail;
    /** `undefined` while deferred; `[]` means there are genuinely none. */
    challenges: ChallengeSummary[] | undefined;
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

                <CardGrid
                    layout="stack"
                    items={challenges}
                    itemKey={(challenge) => challenge.slug}
                    label={`Challenges in ${course.title}`}
                    skeleton={<ChallengeRowSkeleton />}
                    skeletonItems={4}
                    empty={
                        <EmptyState>
                            <EmptyStateIcon>
                                <Route />
                            </EmptyStateIcon>
                            <EmptyStateTitle>No challenges yet</EmptyStateTitle>
                            <EmptyStateDescription>
                                This course doesn't have any published
                                challenges yet. Check back soon.
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
            </div>
        </>
    );
}
