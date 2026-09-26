import { Head, Link, router } from '@inertiajs/react';
import { ListTree, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { CourseFormDialog } from '@/components/admin/course-form-dialog';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DataTable } from '@/components/data-table';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import type { ColumnDef } from '@/lib/data-table';
import { visitAsPromise } from '@/lib/inertia-promise';
import type { RowAction } from '@/lib/row-actions';
import { dashboard } from '@/routes/admin';
import { destroy, index, show } from '@/routes/admin/courses';
import type { AdminCourse, Option } from '@/types/admin';
import type { PlanValue } from '@/types/auth';

type CoursesIndexProps = {
    courses: AdminCourse[];
    plans: Option<PlanValue>[];
};

const columns: ColumnDef<AdminCourse>[] = [
    {
        id: 'order',
        header: '#',
        width: 'w-10',
        cell: (course) => (
            <span className="text-muted-foreground tabular-nums">
                {course.order}
            </span>
        ),
    },
    {
        id: 'title',
        header: 'Course',
        cell: (course) => (
            <div className="min-w-0">
                <Link
                    href={show(course.slug)}
                    className="block truncate font-medium hover:underline"
                >
                    {course.title}
                </Link>
                <span className="block truncate font-mono text-xs text-muted-foreground">
                    {course.slug}
                </span>
            </div>
        ),
    },
    {
        id: 'tier',
        header: 'Tier',
        hideBelow: 'sm',
        cell: (course) => (
            <div className="flex flex-wrap gap-1">
                <Badge variant="outline">{course.requiredPlan}</Badge>
                <Badge variant="outline">{course.difficulty}</Badge>
            </div>
        ),
    },
    {
        id: 'missions',
        header: 'Missions',
        align: 'end',
        cell: (course) => (
            <span className="tabular-nums">
                {course.publishedChallenges} / {course.challenges}
                <span className="sr-only"> published</span>
            </span>
        ),
    },
    {
        id: 'quizzes',
        header: 'Quizzes',
        align: 'end',
        hideBelow: 'md',
        cell: (course) => course.quizzes,
    },
    {
        id: 'status',
        header: 'Status',
        align: 'end',
        cell: (course) =>
            course.isPublished ? (
                <Badge variant="secondary">Published</Badge>
            ) : (
                <Badge variant="outline">Draft</Badge>
            ),
    },
];

export default function CoursesIndex({ courses, plans }: CoursesIndexProps) {
    const [editing, setEditing] = useState<AdminCourse | null>(null);
    const [deleting, setDeleting] = useState<AdminCourse | null>(null);

    function actionsFor(course: AdminCourse): RowAction[] {
        return [
            {
                label: 'Manage missions',
                icon: <ListTree />,
                href: show(course.slug),
            },
            {
                label: 'Edit',
                icon: <Pencil />,
                onSelect: () => setEditing(course),
            },
            {
                label: 'Delete course',
                icon: <Trash2 />,
                variant: 'destructive',
                group: 'danger',
                onSelect: () => setDeleting(course),
            },
        ];
    }

    return (
        <>
            <Head title="Course admin" />

            <div className="space-y-6 p-4">
                <PageHeader
                    title="Courses"
                    description="The whole catalogue in the order pilots see it, drafts included. Missions show as published of total."
                    actions={
                        <CourseFormDialog
                            plans={plans}
                            trigger={
                                <Button>
                                    <Plus />
                                    New course
                                </Button>
                            }
                        />
                    }
                />

                <DataTable
                    caption="Courses"
                    columns={columns}
                    rows={courses}
                    rowKey={(course) => course.slug}
                    actions={actionsFor}
                    actionsLabel={(course) => `Actions for ${course.title}`}
                    empty={
                        <EmptyState className="rounded-none border-0">
                            <EmptyStateTitle>No courses yet</EmptyStateTitle>
                            <EmptyStateDescription>
                                Create one, or run the course seeder to load the
                                standard catalogue.
                            </EmptyStateDescription>
                        </EmptyState>
                    }
                />
            </div>

            {editing && (
                <CourseFormDialog
                    course={editing}
                    plans={plans}
                    open
                    onOpenChange={(open) => !open && setEditing(null)}
                />
            )}

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                destructive
                title={`Delete ${deleting?.title ?? 'this course'}?`}
                description="Every mission and quiz in it goes too, along with every pilot's progress, runs, attempts and photos on them. This cannot be undone."
                confirmationText={deleting?.slug}
                confirmLabel="Delete course"
                pendingLabel="Deleting…"
                onConfirm={() =>
                    visitAsPromise(
                        (options) =>
                            router.delete(
                                destroy.url(deleting?.slug ?? ''),
                                options,
                            ),
                        {},
                        'The course was not deleted',
                    )
                }
            />
        </>
    );
}

CoursesIndex.layout = {
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Courses', href: index() },
    ],
};
