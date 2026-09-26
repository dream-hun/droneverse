import { Link, router } from '@inertiajs/react';
import { ExternalLink, ListChecks, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { QuizFormDialog } from '@/components/admin/quiz-form-dialog';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DataTable } from '@/components/data-table';
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
import { destroy, show } from '@/routes/admin/courses/quizzes';
import { show as takeQuiz } from '@/routes/quizzes';
import type { AdminCourse, AdminQuiz, Option } from '@/types/admin';
import type { PlanValue } from '@/types/auth';

type CourseQuizzesProps = {
    course: AdminCourse;
    quizzes: AdminQuiz[];
    plans: Option<PlanValue>[];
};

/**
 * A course's quizzes, on the course's admin page beneath its missions.
 *
 * Details are edited here in a modal; questions are written on each quiz's
 * own page, which is where "Manage questions" and a new quiz both lead.
 */
export function CourseQuizzes({ course, quizzes, plans }: CourseQuizzesProps) {
    const [editing, setEditing] = useState<AdminQuiz | null>(null);
    const [deleting, setDeleting] = useState<AdminQuiz | null>(null);

    const nextOrder = quizzes.reduce(
        (max, quiz) => Math.max(max, quiz.order + 1),
        0,
    );

    const columns: ColumnDef<AdminQuiz>[] = [
        {
            id: 'order',
            header: '#',
            width: 'w-10',
            cell: (quiz) => (
                <span className="text-muted-foreground tabular-nums">
                    {quiz.order}
                </span>
            ),
        },
        {
            id: 'title',
            header: 'Quiz',
            cell: (quiz) => (
                <div className="min-w-0">
                    <Link
                        href={show([course.slug, quiz.slug])}
                        className="block truncate font-medium hover:underline"
                    >
                        {quiz.title}
                    </Link>
                    <span className="block truncate font-mono text-xs text-muted-foreground">
                        {quiz.slug}
                    </span>
                </div>
            ),
        },
        {
            id: 'pass',
            header: 'Pass mark',
            align: 'end',
            hideBelow: 'sm',
            cell: (quiz) => `${quiz.passPercentage}%`,
        },
        {
            id: 'questions',
            header: 'Questions',
            align: 'end',
            cell: (quiz) =>
                quiz.questions === 0 ? (
                    <span className="text-destructive">None yet</span>
                ) : (
                    formatCount(quiz.questions)
                ),
        },
        {
            id: 'pilots',
            header: 'Pilots',
            align: 'end',
            hideBelow: 'md',
            cell: (quiz) => formatCount(quiz.pilots),
        },
        {
            id: 'status',
            header: 'Status',
            align: 'end',
            cell: (quiz) =>
                quiz.isPublished ? (
                    <Badge variant="secondary">Published</Badge>
                ) : (
                    <Badge variant="outline">Draft</Badge>
                ),
        },
    ];

    function actionsFor(quiz: AdminQuiz): RowAction[] {
        return [
            {
                label: 'Manage questions',
                icon: <ListChecks />,
                href: show([course.slug, quiz.slug]),
            },
            {
                label: 'Edit',
                icon: <Pencil />,
                onSelect: () => setEditing(quiz),
            },
            ...(quiz.isPublished && course.isPublished
                ? [
                      {
                          label: 'Open as a pilot',
                          icon: <ExternalLink />,
                          href: takeQuiz([course.slug, quiz.slug]),
                      },
                  ]
                : []),
            {
                label: 'Delete quiz',
                icon: <Trash2 />,
                variant: 'destructive' as const,
                group: 'danger',
                onSelect: () => setDeleting(quiz),
            },
        ];
    }

    return (
        <section className="space-y-3">
            <div className="flex items-center justify-between gap-2">
                <h2 className="text-base font-semibold">Quizzes</h2>
                <QuizFormDialog
                    course={course}
                    plans={plans}
                    nextOrder={nextOrder}
                    trigger={
                        <Button variant="outline" size="sm">
                            <Plus />
                            New quiz
                        </Button>
                    }
                />
            </div>

            <DataTable
                caption={`Quizzes in ${course.title}`}
                columns={columns}
                rows={quizzes}
                rowKey={(quiz) => quiz.slug}
                actions={actionsFor}
                actionsLabel={(quiz) => `Actions for ${quiz.title}`}
                empty={
                    <EmptyState className="rounded-none border-0">
                        <EmptyStateTitle>No quizzes yet</EmptyStateTitle>
                        <EmptyStateDescription>
                            A quiz checks that pilots understood why the
                            missions work, not just that they flew them.
                        </EmptyStateDescription>
                    </EmptyState>
                }
            />

            {editing && (
                <QuizFormDialog
                    key={editing.slug}
                    course={course}
                    quiz={editing}
                    plans={plans}
                    open
                    onOpenChange={(open) => !open && setEditing(null)}
                />
            )}

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                destructive
                title={`Delete ${deleting?.title ?? 'this quiz'}?`}
                description={`Its questions go with it, along with every attempt and pass recorded on it — ${formatCount(deleting?.pilots ?? 0)} pilot${deleting?.pilots === 1 ? '' : 's'} have taken it.`}
                confirmationText={deleting?.slug}
                confirmLabel="Delete quiz"
                pendingLabel="Deleting…"
                onConfirm={() =>
                    visitAsPromise(
                        (options) =>
                            router.delete(
                                destroy.url([
                                    course.slug,
                                    deleting?.slug ?? '',
                                ]),
                                options,
                            ),
                        {},
                        'The quiz was not deleted',
                    )
                }
            />
        </section>
    );
}
