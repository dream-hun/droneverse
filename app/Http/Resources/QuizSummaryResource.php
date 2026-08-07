<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Plan;
use App\Enums\QuizStatus;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\UserQuizProgress;
use Illuminate\Support\Collection;

/**
 * A quiz row on a course page, merged with the viewer's progress.
 *
 * The quiz counterpart of {@see ChallengeSummaryResource}, down to the
 * defaults: a guest — or a pilot who has never opened the quiz — has no
 * progress row, which is the same thing as not having started.
 *
 * Rows carry `locked` so the page can render a quiz the viewer cannot take as
 * an upgrade prompt rather than a dead link. The description is still sent —
 * what the quiz covers is the advertisement — while the questions, the options
 * and the answer key are not on this shape at all, so nothing withheld leaks
 * through the lock. Mirrors the `QuizSummary` type in
 * resources/js/types/quiz.ts.
 */
final class QuizSummaryResource
{
    /**
     * @param  Collection<int, Quiz>  $quizzes  loaded with `questions_count`
     * @param  Collection<int, UserQuizProgress>  $progressByQuiz  keyed by quiz id
     * @return array<int, array{title: string, slug: string, description: string, questionCount: int, passPercentage: int, requiredPlan: string, locked: bool, status: string, bestScore: int, attempts: int, passed: bool}>
     */
    public static function collection(
        Collection $quizzes,
        Collection $progressByQuiz,
        Course $course,
        Plan $viewerPlan,
    ): array {
        return $quizzes
            ->map(function (Quiz $quiz) use ($progressByQuiz, $course, $viewerPlan): array {
                $progress = $progressByQuiz->get($quiz->id);
                $requiredPlan = $quiz->requiredPlanIn($course);
                $status = $progress?->status() ?? QuizStatus::NotStarted;

                return [
                    'title' => $quiz->title,
                    'slug' => $quiz->slug,
                    'description' => $quiz->description,
                    'questionCount' => $quiz->questions_count ?? 0,
                    'passPercentage' => $quiz->pass_percentage,
                    'requiredPlan' => $requiredPlan->value,
                    'locked' => ! $viewerPlan->covers($requiredPlan),
                    'status' => $status->value,
                    'bestScore' => $progress->best_score ?? 0,
                    'attempts' => $progress->attempts ?? 0,
                    'passed' => $status === QuizStatus::Passed,
                ];
            })
            ->all();
    }
}
