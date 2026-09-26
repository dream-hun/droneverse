import { Head, router, setLayoutProps } from '@inertiajs/react';
import { ExternalLink, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ChallengeFormDialog } from '@/components/admin/challenge-form-dialog';
import { CourseFormDialog } from '@/components/admin/course-form-dialog';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DataTable } from '@/components/data-table';
import { PageHeader } from '@/components/page-header';
import { RowActions } from '@/components/row-actions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import { formatCount } from '@/lib/admin-format';
import type { ColumnDef } from '@/lib/data-table';
import { visitAsPromise } from '@/lib/inertia-promise';
import type { RowAction } from '@/lib/row-actions';
import { dashboard } from '@/routes/admin';
import { destroy as destroyCourse, index, show } from '@/routes/admin/courses';
import { destroy as destroyChallenge } from '@/routes/admin/courses/challenges';
import { show as playChallenge } from '@/routes/challenges';
import { show as publicCourse } from '@/routes/courses';
import type { AdminChallenge, AdminCourse, Option } from '@/types/admin';
import type { PlanValue } from '@/types/auth';

type CourseShowProps = {
    course: AdminCourse;
    challenges: AdminChallenge[];
    plans: Option<PlanValue>[];
};

export default function CourseShow({
    course,
    challenges,
    plans,
}: CourseShowProps) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Admin', href: dashboard() },
            { title: 'Courses', href: index() },
            { title: course.title, href: show(course.slug) },
        ],
    });

    const [editingCourse, setEditingCourse] = useState(false);
    const [deletingCourse, setDeletingCourse] = useState(false);
    const [editing, setEditing] = useState<AdminChallenge | null>(null);
    const [deleting, setDeleting] = useState<AdminChallenge | null>(null);

    const nextOrder = challenges.reduce(
        (max, challenge) => Math.max(max, challenge.order + 1),
        0,
    );

    const columns: ColumnDef<AdminChallenge>[] = [
        {
            id: 'order',
            header: '#',
            width: 'w-10',
            cell: (challenge) => (
                <span className="text-muted-foreground tabular-nums">
                    {challenge.order}
                </span>
            ),
        },
        {
            id: 'title',
            header: 'Mission',
            cell: (challenge) => (
                <div className="min-w-0">
                    <button
                        type="button"
                        onClick={() => setEditing(challenge)}
                        className="block truncate text-left font-medium hover:underline"
                    >
                        {challenge.title}
                    </button>
                    <span className="block truncate font-mono text-xs text-muted-foreground">
                        {challenge.slug}
                    </span>
                </div>
            ),
        },
        {
            id: 'tier',
            header: 'Tier',
            hideBelow: 'sm',
            cell: (challenge) => (
                <div className="flex flex-wrap gap-1">
                    <Badge variant="outline">
                        {challenge.requiredPlan ??
                            `${course.requiredPlan} (course)`}
                    </Badge>
                    <Badge variant="outline">{challenge.difficulty}</Badge>
                </div>
            ),
        },
        {
            id: 'solution',
            header: 'Solution',
            hideBelow: 'lg',
            cell: (challenge) =>
                challenge.solutionCode ? (
                    'Written'
                ) : (
                    <span className="text-muted-foreground">None</span>
                ),
        },
        {
            id: 'pilots',
            header: 'Pilots',
            align: 'end',
            hideBelow: 'md',
            cell: (challenge) => formatCount(challenge.pilots),
        },
        {
            id: 'status',
            header: 'Status',
            align: 'end',
            cell: (challenge) =>
                challenge.isPublished ? (
                    <Badge variant="secondary">Published</Badge>
                ) : (
                    <Badge variant="outline">Draft</Badge>
                ),
        },
    ];

    function actionsFor(challenge: AdminChallenge): RowAction[] {
        return [
            {
                label: 'Edit',
                icon: <Pencil />,
                onSelect: () => setEditing(challenge),
            },
            ...(challenge.isPublished && course.isPublished
                ? [
                      {
                          label: 'Open as a pilot',
                          icon: <ExternalLink />,
                          href: playChallenge([course.slug, challenge.slug]),
                      },
                  ]
                : []),
            {
                label: 'Delete mission',
                icon: <Trash2 />,
                variant: 'destructive',
                group: 'danger',
                onSelect: () => setDeleting(challenge),
            },
        ];
    }

    const courseActions: RowAction[] = [
        {
            label: 'Edit course',
            icon: <Pencil />,
            onSelect: () => setEditingCourse(true),
        },
        ...(course.isPublished
            ? [
                  {
                      label: 'Open course page',
                      icon: <ExternalLink />,
                      href: publicCourse(course.slug),
                  },
              ]
            : []),
        {
            label: 'Delete course',
            icon: <Trash2 />,
            variant: 'destructive',
            group: 'danger',
            onSelect: () => setDeletingCourse(true),
        },
    ];

    return (
        <>
            <Head title={course.title} />

            <div className="space-y-6 p-4">
                <PageHeader
                    title={course.title}
                    description={course.description}
                    badge={
                        <div className="flex flex-wrap gap-1">
                            {course.isPublished ? (
                                <Badge variant="secondary">Published</Badge>
                            ) : (
                                <Badge variant="outline">Draft</Badge>
                            )}
                            <Badge variant="outline">
                                {course.requiredPlan}
                            </Badge>
                            <Badge variant="outline">{course.difficulty}</Badge>
                        </div>
                    }
                    actions={
                        <>
                            <ChallengeFormDialog
                                course={course}
                                plans={plans}
                                nextOrder={nextOrder}
                                trigger={
                                    <Button>
                                        <Plus />
                                        New mission
                                    </Button>
                                }
                            />
                            <RowActions
                                actions={courseActions}
                                label={`Actions for ${course.title}`}
                                className="border"
                            />
                        </>
                    }
                />

                <DataTable
                    caption={`Missions in ${course.title}`}
                    columns={columns}
                    rows={challenges}
                    rowKey={(challenge) => challenge.slug}
                    actions={actionsFor}
                    actionsLabel={(challenge) =>
                        `Actions for ${challenge.title}`
                    }
                    empty={
                        <EmptyState className="rounded-none border-0">
                            <EmptyStateTitle>No missions yet</EmptyStateTitle>
                            <EmptyStateDescription>
                                A new mission starts from a flyable take-off,
                                hover and land, so it can be tried before it is
                                published.
                            </EmptyStateDescription>
                        </EmptyState>
                    }
                />
            </div>

            {editingCourse && (
                <CourseFormDialog
                    course={course}
                    plans={plans}
                    open
                    onOpenChange={setEditingCourse}
                />
            )}

            {editing && (
                <ChallengeFormDialog
                    key={editing.slug}
                    course={course}
                    challenge={editing}
                    plans={plans}
                    open
                    onOpenChange={(open) => !open && setEditing(null)}
                />
            )}

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                destructive
                title={`Delete ${deleting?.title ?? 'this mission'}?`}
                description={`${formatCount(deleting?.pilots ?? 0)} pilot${deleting?.pilots === 1 ? ' has' : 's have'} progress on it. Their runs, progress and photos on this mission go with it, and the course's leaderboard is recomputed.`}
                confirmationText={deleting?.slug}
                confirmLabel="Delete mission"
                pendingLabel="Deleting…"
                onConfirm={() =>
                    visitAsPromise(
                        (options) =>
                            router.delete(
                                destroyChallenge.url([
                                    course.slug,
                                    deleting?.slug ?? '',
                                ]),
                                options,
                            ),
                        {},
                        'The mission was not deleted',
                    )
                }
            />

            <ConfirmDialog
                open={deletingCourse}
                onOpenChange={setDeletingCourse}
                destructive
                title={`Delete ${course.title}?`}
                description="Every mission and quiz in it goes too, along with every pilot's progress, runs, attempts and photos on them. This cannot be undone."
                confirmationText={course.slug}
                confirmLabel="Delete course"
                pendingLabel="Deleting…"
                onConfirm={() =>
                    visitAsPromise(
                        (options) =>
                            router.delete(
                                destroyCourse.url(course.slug),
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
