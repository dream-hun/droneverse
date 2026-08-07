import { Head, setLayoutProps, useHttp } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { PageHeader } from '@/components/page-header';
import { QuizQuestionCard } from '@/components/quiz/quiz-question-card';
import { QuizResultSummary } from '@/components/quiz/quiz-result-summary';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { index as coursesIndex, show as showCourse } from '@/routes/courses';
import { store as storeAttempt } from '@/routes/quizzes/attempts';
import type {
    QuizAnswers,
    QuizDetail,
    QuizProgress,
    QuizQuestionResult,
    QuizResult,
} from '@/types/quiz';

type QuizShowProps = {
    course: { title: string; slug: string };
    quiz: QuizDetail;
    progress: QuizProgress;
};

export default function QuizShow({ course, quiz, progress }: QuizShowProps) {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Courses', href: coursesIndex() },
            { title: course.title, href: showCourse(course.slug) },
            { title: quiz.title, href: showCourse(course.slug) },
        ],
    });

    const [result, setResult] = useState<QuizResult | null>(null);
    const questionsRef = useRef<HTMLDivElement>(null);

    /*
     * The selection lives in the hook rather than in local state beside it.
     * `submit()` sends whatever `data` holds at the moment it is called, so a
     * second copy in `useState` would be one render behind on the last box
     * ticked before submitting — the classic stale-closure bug, and here it
     * would silently drop an answer the pilot watched themselves give.
     */
    const { data, setData, submit, processing, reset } = useHttp<
        { answers: QuizAnswers },
        QuizResult
    >('post', storeAttempt.url([course.slug, quiz.slug]), { answers: {} });

    const answers = data.answers;

    /** Graded outcomes keyed by question id, for the cards to read. */
    const resultsByQuestion = useMemo(() => {
        const map = new Map<number, QuizQuestionResult>();

        result?.questions.forEach((question) => map.set(question.id, question));

        return map;
    }, [result]);

    const answeredCount = quiz.questions.filter(
        (question) => (answers[String(question.id)] ?? []).length > 0,
    ).length;

    const allAnswered = answeredCount === quiz.questions.length;

    async function grade() {
        try {
            const graded = await submit();

            setResult(graded);

            // The review renders above the questions, and on a long quiz the
            // pilot is at the bottom when they submit — without this they are
            // told nothing happened.
            questionsRef.current?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });

            if (graded.passed) {
                toast.success(`Passed with ${graded.score}%.`);
            } else {
                toast.error(
                    `Scored ${graded.score}% — ${quiz.passPercentage}% needed to pass.`,
                );
            }
        } catch {
            toast.error('Could not submit your answers. Please try again.');
        }
    }

    function retake() {
        reset();
        setResult(null);
        questionsRef.current?.scrollIntoView({
            behavior: 'smooth',
            block: 'start',
        });
    }

    return (
        <>
            <Head title={quiz.title} />

            <div className="mx-auto max-w-3xl space-y-6 p-4">
                <PageHeader
                    title={quiz.title}
                    description={quiz.description}
                    badge={
                        <Badge variant="outline">
                            {quiz.questions.length} question
                            {quiz.questions.length === 1 ? '' : 's'}
                        </Badge>
                    }
                    actions={
                        progress.passed ? (
                            <Badge className="bg-emerald-600 text-white hover:bg-emerald-600">
                                Passed · best {progress.bestScore}%
                            </Badge>
                        ) : progress.attempts > 0 ? (
                            <Badge variant="secondary">
                                Best {progress.bestScore}% · {progress.attempts}{' '}
                                attempt{progress.attempts === 1 ? '' : 's'}
                            </Badge>
                        ) : undefined
                    }
                />

                <div ref={questionsRef} className="space-y-4">
                    {result && (
                        <QuizResultSummary
                            result={result}
                            passPercentage={quiz.passPercentage}
                            onRetake={retake}
                        />
                    )}

                    {quiz.questions.map((question, index) => (
                        <QuizQuestionCard
                            key={question.id}
                            question={question}
                            index={index}
                            selected={answers[String(question.id)] ?? []}
                            result={resultsByQuestion.get(question.id)}
                            onChange={(optionIds) =>
                                setData('answers', {
                                    ...answers,
                                    [String(question.id)]: optionIds,
                                })
                            }
                        />
                    ))}
                </div>

                {!result && (
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                        <p className="text-sm text-muted-foreground">
                            {answeredCount} of {quiz.questions.length} answered
                            {!allAnswered &&
                                ' — unanswered questions are marked wrong.'}
                        </p>
                        <Button
                            onClick={grade}
                            disabled={processing || quiz.questions.length === 0}
                        >
                            {processing && <Spinner data-icon="inline-start" />}
                            Submit answers
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}
