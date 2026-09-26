<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\CreateQuiz;
use App\Actions\DeleteQuiz;
use App\Actions\UpdateQuiz;
use App\Enums\Plan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveQuizRequest;
use App\Http\Resources\Admin\AdminCourseResource;
use App\Http\Resources\Admin\AdminQuizQuestionResource;
use App\Http\Resources\Admin\AdminQuizResource;
use App\Models\Course;
use App\Models\Quiz;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A course's quizzes, created from the course's admin page and edited on
 * their own, where their questions are.
 *
 * The routes bind the quiz scoped to the course, so a slug from another course
 * is a 404 before anything here runs.
 */
final class QuizController extends Controller
{
    public function store(SaveQuizRequest $request, Course $course, CreateQuiz $create): RedirectResponse
    {
        $quiz = $create->handle($course, $request->quiz());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Quiz :title created. Add its questions here.', ['title' => $quiz->title])]);

        return to_route('admin.courses.quizzes.show', [$course, $quiz]);
    }

    /**
     * A quiz and every question on it, answer key included, for editing.
     */
    public function show(Course $course, Quiz $quiz): Response
    {
        $quiz->loadCount(['questions', 'progress']);

        return Inertia::render('admin/quizzes/show', [
            'course' => AdminCourseResource::one($course),
            'quiz' => AdminQuizResource::one($quiz),
            'questions' => AdminQuizQuestionResource::collection(
                $quiz->questions()->with('options')->get(),
            ),
            'plans' => array_map(
                static fn (Plan $plan): array => ['value' => $plan->value, 'label' => $plan->label()],
                Plan::cases(),
            ),
        ]);
    }

    /**
     * A renamed quiz has moved, so an edit made from its own page follows it
     * there rather than going back to the old slug's 404.
     */
    public function update(SaveQuizRequest $request, Course $course, Quiz $quiz, UpdateQuiz $update): RedirectResponse
    {
        $wasOnQuizPage = url()->previous() === route('admin.courses.quizzes.show', [$course, $quiz]);

        $update->handle($quiz, $request->quiz());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Quiz updated.')]);

        return $wasOnQuizPage ? to_route('admin.courses.quizzes.show', [$course, $quiz]) : back();
    }

    public function destroy(Course $course, Quiz $quiz, DeleteQuiz $delete): RedirectResponse
    {
        $delete->handle($quiz);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Quiz :title deleted.', ['title' => $quiz->title])]);

        return to_route('admin.courses.show', $course);
    }
}
