<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\CreateQuizQuestion;
use App\Actions\DeleteQuizQuestion;
use App\Actions\UpdateQuizQuestion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveQuizQuestionRequest;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Throwable;

/**
 * A quiz's questions, edited from the quiz's admin page.
 *
 * Bound down the whole chain — course, quiz, question — so a question from a
 * different quiz is a 404, however real its uuid.
 */
final class QuizQuestionController extends Controller
{
    /**
     * @throws Throwable
     */
    public function store(SaveQuizQuestionRequest $request, Course $course, Quiz $quiz, CreateQuizQuestion $create): RedirectResponse
    {
        $create->handle($quiz, $request->question());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Question added.')]);

        return to_route('admin.courses.quizzes.show', [$course, $quiz]);
    }

    /**
     * @throws Throwable
     */
    public function update(SaveQuizQuestionRequest $request, Course $course, Quiz $quiz, QuizQuestion $question, UpdateQuizQuestion $update): RedirectResponse
    {
        $update->handle($question, $request->question());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Question updated.')]);

        return to_route('admin.courses.quizzes.show', [$course, $quiz]);
    }

    public function destroy(Course $course, Quiz $quiz, QuizQuestion $question, DeleteQuizQuestion $delete): RedirectResponse
    {
        $delete->handle($question);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Question deleted.')]);

        return to_route('admin.courses.quizzes.show', [$course, $quiz]);
    }
}
