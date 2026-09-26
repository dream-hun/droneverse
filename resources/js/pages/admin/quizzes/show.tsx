import { Head, router, setLayoutProps } from '@inertiajs/react';
import { ExternalLink, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { QuestionFormDialog } from '@/components/admin/question-form-dialog';
import { QuizFormDialog } from '@/components/admin/quiz-form-dialog';
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
import {
    index as coursesIndex,
    show as showCourse,
} from '@/routes/admin/courses';
import { destroy as destroyQuiz, show } from '@/routes/admin/courses/quizzes';
import { destroy as destroyQuestion } from '@/routes/admin/courses/quizzes/questions';
import { show as takeQuiz } from '@/routes/quizzes';
import type {
    AdminCourse,
    AdminQuiz,
    AdminQuizQuestion,
    Option,
} from '@/types/admin';
import type { PlanValue } from '@/types/auth';

type QuizShowProps = {
    course: AdminCourse;
    quiz: AdminQuiz;
    questions: AdminQuizQuestion[];
    plans: Option<PlanValue>[];
};

export default function QuizShow({
    course,
    quiz,
    questions,
    plans,
}: QuizShowProps) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Admin', href: dashboard() },
            { title: 'Courses', href: coursesIndex() },
            { title: course.title, href: showCourse(course.slug) },
            { title: quiz.title, href: show([course.slug, quiz.slug]) },
        ],
    });

    const [editingQuiz, setEditingQuiz] = useState(false);
    const [deletingQuiz, setDeletingQuiz] = useState(false);
    const [editing, setEditing] = useState<AdminQuizQuestion | null>(null);
    const [deleting, setDeleting] = useState<AdminQuizQuestion | null>(null);

    const nextOrder = questions.reduce(
        (max, question) => Math.max(max, question.order + 1),
        0,
    );

    const columns: ColumnDef<AdminQuizQuestion>[] = [
        {
            id: 'order',
            header: '#',
            width: 'w-10',
            cell: (question) => (
                <span className="text-muted-foreground tabular-nums">
                    {question.order}
                </span>
            ),
        },
        {
            id: 'prompt',
            header: 'Question',
            cell: (question) => (
                <button
                    type="button"
                    onClick={() => setEditing(question)}
                    className="line-clamp-2 max-w-xl text-left font-medium hover:underline"
                >
                    {question.prompt}
                </button>
            ),
        },
        {
            id: 'answers',
            header: 'Answers',
            hideBelow: 'sm',
            cell: (question) => {
                const correct = question.options.filter(
                    (option) => option.isCorrect,
                ).length;

                return (
                    <div className="flex flex-wrap items-center gap-1">
                        <Badge variant="outline">
                            {question.type === 'multiple'
                                ? 'Pick all'
                                : 'Pick one'}
                        </Badge>
                        <span className="text-xs text-muted-foreground">
                            {correct} of {question.options.length} correct
                        </span>
                    </div>
                );
            },
        },
        {
            id: 'explanation',
            header: 'Explained',
            align: 'end',
            hideBelow: 'md',
            cell: (question) =>
                question.explanation ? (
                    'Yes'
                ) : (
                    <span className="text-muted-foreground">No</span>
                ),
        },
    ];

    function actionsFor(question: AdminQuizQuestion): RowAction[] {
        return [
            {
                label: 'Edit',
                icon: <Pencil />,
                onSelect: () => setEditing(question),
            },
            {
                label: 'Delete question',
                icon: <Trash2 />,
                variant: 'destructive',
                group: 'danger',
                onSelect: () => setDeleting(question),
            },
        ];
    }

    const quizActions: RowAction[] = [
        {
            label: 'Edit quiz',
            icon: <Pencil />,
            onSelect: () => setEditingQuiz(true),
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
            variant: 'destructive',
            group: 'danger',
            onSelect: () => setDeletingQuiz(true),
        },
    ];

    return (
        <>
            <Head title={quiz.title} />

            <div className="space-y-6 p-4">
                <PageHeader
                    title={quiz.title}
                    description={quiz.description}
                    badge={
                        <div className="flex flex-wrap gap-1">
                            {quiz.isPublished ? (
                                <Badge variant="secondary">Published</Badge>
                            ) : (
                                <Badge variant="outline">Draft</Badge>
                            )}
                            <Badge variant="outline">
                                Pass at {quiz.passPercentage}%
                            </Badge>
                            <Badge variant="outline">
                                {quiz.requiredPlan ??
                                    `${course.requiredPlan} (course)`}
                            </Badge>
                        </div>
                    }
                    actions={
                        <>
                            <QuestionFormDialog
                                course={course}
                                quiz={quiz}
                                nextOrder={nextOrder}
                                trigger={
                                    <Button>
                                        <Plus />
                                        New question
                                    </Button>
                                }
                            />
                            <RowActions
                                actions={quizActions}
                                label={`Actions for ${quiz.title}`}
                                className="border"
                            />
                        </>
                    }
                />

                <DataTable
                    caption={`Questions on ${quiz.title}`}
                    columns={columns}
                    rows={questions}
                    rowKey={(question) => question.uuid}
                    actions={actionsFor}
                    actionsLabel={(question) =>
                        `Actions for question ${question.order}`
                    }
                    empty={
                        <EmptyState className="rounded-none border-0">
                            <EmptyStateTitle>No questions yet</EmptyStateTitle>
                            <EmptyStateDescription>
                                A quiz with no questions scores zero and passes
                                nobody, so write at least one before publishing
                                it.
                            </EmptyStateDescription>
                        </EmptyState>
                    }
                />
            </div>

            {editingQuiz && (
                <QuizFormDialog
                    course={course}
                    quiz={quiz}
                    plans={plans}
                    open
                    onOpenChange={setEditingQuiz}
                />
            )}

            {editing && (
                <QuestionFormDialog
                    key={editing.uuid}
                    course={course}
                    quiz={quiz}
                    question={editing}
                    open
                    onOpenChange={(open) => !open && setEditing(null)}
                />
            )}

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                destructive
                title="Delete this question?"
                description="Pilots who already passed keep their pass. From their next attempt, the score is out of the questions that remain."
                confirmLabel="Delete question"
                pendingLabel="Deleting…"
                onConfirm={() =>
                    visitAsPromise(
                        (options) =>
                            router.delete(
                                destroyQuestion.url([
                                    course.slug,
                                    quiz.slug,
                                    deleting?.uuid ?? '',
                                ]),
                                options,
                            ),
                        {},
                        'The question was not deleted',
                    )
                }
            />

            <ConfirmDialog
                open={deletingQuiz}
                onOpenChange={setDeletingQuiz}
                destructive
                title={`Delete ${quiz.title}?`}
                description={`Its ${formatCount(questions.length)} questions go with it, along with every attempt and pass recorded on it.`}
                confirmationText={quiz.slug}
                confirmLabel="Delete quiz"
                pendingLabel="Deleting…"
                onConfirm={() =>
                    visitAsPromise(
                        (options) =>
                            router.delete(
                                destroyQuiz.url([course.slug, quiz.slug]),
                                options,
                            ),
                        {},
                        'The quiz was not deleted',
                    )
                }
            />
        </>
    );
}
