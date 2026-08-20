<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\QuizStatus;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Models\UserQuizProgress;

test('guests are redirected from a quiz', function (): void {
    [$course, $quiz] = publishedQuiz();

    $this->get(route('quizzes.show', [$course, $quiz]))
        ->assertRedirect(route('login'));
});

test('a pilot can open a published quiz', function (): void {
    [$course, $quiz] = publishedQuiz();

    $this->actingAs(User::factory()->create())
        ->get(route('quizzes.show', [$course, $quiz]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('quizzes/show')
            ->where('quiz.slug', $quiz->slug)
            ->where('quiz.passPercentage', 70)
            ->has('quiz.questions', 4)
            ->has('quiz.questions.0.options', 4)
            ->where('progress.status', QuizStatus::NotStarted->value)
            ->where('progress.attempts', 0));
});

/**
 * The rule the whole feature rests on: the browser is handed the
 * questions and never the answers.
 */
test('the quiz page never ships the answer key', function (): void {
    [$course, $quiz] = publishedQuiz();

    $response = $this->actingAs(User::factory()->create())
        ->get(route('quizzes.show', [$course, $quiz]));

    $response->assertInertia(fn ($page) => $page
        ->missing('quiz.questions.0.options.0.isCorrect')
        ->missing('quiz.questions.0.options.0.is_correct')
        // An explanation says why an answer is right, which gives the
        // answer away as surely as the flag does.
        ->missing('quiz.questions.0.explanation'));

    // And nothing leaks through the serialized payload by another name.
    $this->assertStringNotContainsString('is_correct', $response->getContent() ?: '');
});

test('an unpublished quiz returns not found', function (): void {
    $course = Course::factory()->create();
    $quiz = Quiz::factory()->for($course)->unpublished()->withQuestions()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('quizzes.show', [$course, $quiz]))
        ->assertNotFound();
});

test('a quiz belonging to another course returns not found', function (): void {
    [, $quiz] = publishedQuiz();
    $otherCourse = Course::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('quizzes.show', [$otherCourse, $quiz]))
        ->assertNotFound();
});

test('a quiz in an unpublished course returns not found', function (): void {
    $course = Course::factory()->unpublished()->create();
    $quiz = Quiz::factory()->for($course)->withQuestions()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('quizzes.show', [$course, $quiz]))
        ->assertNotFound();
});

test('a quiz above the viewers plan is forbidden', function (): void {
    $course = Course::factory()->create();
    $quiz = Quiz::factory()->for($course)->requiring(Plan::Pro)->withQuestions()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('quizzes.show', [$course, $quiz]))
        ->assertForbidden();
});

test('a quiz inherits its courses plan', function (): void {
    $course = Course::factory()->requiring(Plan::Pro)->create();
    $quiz = Quiz::factory()->for($course)->withQuestions()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('quizzes.show', [$course, $quiz]))
        ->assertForbidden();

    $this->actingAs(User::factory()->onPlan(Plan::Pro)->create())
        ->get(route('quizzes.show', [$course, $quiz]))
        ->assertOk();
});

/**
 * A locked quiz that still accepts submissions is not locked — it just
 * has no link.
 */
test('the attempt endpoint enforces the plan too', function (): void {
    $course = Course::factory()->create();
    $quiz = Quiz::factory()->for($course)->requiring(Plan::Pro)->withQuestions()->create();

    $this->actingAs(User::factory()->create())
        ->postJson(route('quizzes.attempts.store', [$course, $quiz]), [
            'answers' => correctAnswers($quiz),
        ])
        ->assertForbidden();

    $this->assertDatabaseCount('quiz_attempts', 0);
});

test('a fully correct submission passes and is recorded', function (): void {
    [$course, $quiz] = publishedQuiz();
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson(route('quizzes.attempts.store', [$course, $quiz]), [
            'answers' => correctAnswers($quiz),
        ]);

    $response->assertOk();
    $response->assertJson([
        'score' => 100,
        'correctCount' => 4,
        'questionCount' => 4,
        'passed' => true,
        'progress' => [
            'status' => QuizStatus::Passed->value,
            'bestScore' => 100,
            'attempts' => 1,
            'passed' => true,
        ],
    ]);

    $this->assertDatabaseHas('user_quiz_progress', [
        'user_id' => $user->id,
        'quiz_id' => $quiz->id,
        'best_score' => 100,
        'attempts' => 1,
    ]);

    $this->assertDatabaseHas('quiz_attempts', [
        'user_id' => $user->id,
        'quiz_id' => $quiz->id,
        'score' => 100,
        'correct_count' => 4,
        'question_count' => 4,
        'passed' => true,
    ]);
});

test('a partly correct submission scores proportionally and does not pass', function (): void {
    [$course, $quiz] = publishedQuiz();

    // Two of four right: 50%, under the 70% bar.
    $answers = array_slice(correctAnswers($quiz), 0, 2, true);

    $this->actingAs(User::factory()->create())
        ->postJson(route('quizzes.attempts.store', [$course, $quiz]), ['answers' => $answers])
        ->assertOk()
        ->assertJson([
            'score' => 50,
            'correctCount' => 2,
            'passed' => false,
            'progress' => ['status' => QuizStatus::Attempted->value, 'passed' => false],
        ]);
});

/**
 * Skipping a question has to cost the same as getting it wrong, or
 * leaving the hard ones blank would be the highest-scoring way to take
 * the quiz.
 */
test('unanswered questions are scored as wrong', function (): void {
    [$course, $quiz] = publishedQuiz();

    $this->actingAs(User::factory()->create())
        ->postJson(route('quizzes.attempts.store', [$course, $quiz]), ['answers' => []])
        ->assertOk()
        ->assertJson(['score' => 0, 'correctCount' => 0, 'questionCount' => 4, 'passed' => false]);
});

test('a multiple answer question needs the exact set', function (): void {
    $course = Course::factory()->create();
    $quiz = Quiz::factory()->for($course)->create();

    $question = QuizQuestion::factory()
        ->for($quiz)
        ->multiple()
        ->withOptions(correct: 2)
        ->create();

    $options = $question->options()->orderBy('order')->pluck('id')->all();
    $correct = [$options[0], $options[1]];

    $submit = fn (array $selection) => $this->actingAs(User::factory()->create())
        ->postJson(route('quizzes.attempts.store', [$course, $quiz]), [
            'answers' => [$question->id => $selection],
        ]);

    // Exactly right.
    $submit($correct)->assertJson(['correctCount' => 1, 'score' => 100]);

    // One of the two — no partial credit.
    $submit([$options[0]])->assertJson(['correctCount' => 0, 'score' => 0]);

    // Everything ticked — shotgunning is the strategy the rule rules out.
    $submit($options)->assertJson(['correctCount' => 0, 'score' => 0]);
});

/**
 * The submission describes what the pilot chose, not how they did.
 */
test('a client cannot post its own score', function (): void {
    [$course, $quiz] = publishedQuiz();

    $this->actingAs(User::factory()->create())
        ->postJson(route('quizzes.attempts.store', [$course, $quiz]), [
            'answers' => [],
            'score' => 100,
            'correctCount' => 4,
            'passed' => true,
        ])
        ->assertOk()
        ->assertJson(['score' => 0, 'correctCount' => 0, 'passed' => false]);

    $this->assertDatabaseHas('quiz_attempts', ['score' => 0, 'passed' => false]);
});

test('an option from another quiz is rejected', function (): void {
    [$course, $quiz] = publishedQuiz();
    [, $otherQuiz] = publishedQuiz();

    $foreignOptionId = $otherQuiz->questions()->first()->options()->first()->id;
    $questionId = $quiz->questions()->first()->id;

    $this->actingAs(User::factory()->create())
        ->postJson(route('quizzes.attempts.store', [$course, $quiz]), [
            'answers' => [$questionId => [$foreignOptionId]],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('answers');

    $this->assertDatabaseCount('quiz_attempts', 0);
});

test('a question from another quiz is rejected', function (): void {
    [$course, $quiz] = publishedQuiz();
    [, $otherQuiz] = publishedQuiz();

    $foreignQuestion = $otherQuiz->questions()->first();

    $this->actingAs(User::factory()->create())
        ->postJson(route('quizzes.attempts.store', [$course, $quiz]), [
            'answers' => [$foreignQuestion->id => [$foreignQuestion->options()->first()->id]],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('answers');
});

test('the best score only ever rises', function (): void {
    [$course, $quiz] = publishedQuiz();
    $user = User::factory()->create();
    $correct = correctAnswers($quiz);

    $this->actingAs($user)->postJson(
        route('quizzes.attempts.store', [$course, $quiz]),
        ['answers' => $correct],
    )->assertJson(['score' => 100]);

    // A worse retake reports its own score but must not lower the best.
    $this->actingAs($user)->postJson(
        route('quizzes.attempts.store', [$course, $quiz]),
        ['answers' => []],
    )->assertJson([
        'score' => 0,
        'passed' => false,
        'progress' => ['bestScore' => 100, 'attempts' => 2, 'passed' => true],
    ]);

    $this->assertDatabaseHas('user_quiz_progress', [
        'user_id' => $user->id,
        'quiz_id' => $quiz->id,
        'best_score' => 100,
        'attempts' => 2,
    ]);
});

test('a pass is never revoked by a later attempt', function (): void {
    [$course, $quiz] = publishedQuiz();
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(
        route('quizzes.attempts.store', [$course, $quiz]),
        ['answers' => correctAnswers($quiz)],
    );

    $passedAt = UserQuizProgress::query()
        ->where('user_id', $user->id)
        ->where('quiz_id', $quiz->id)
        ->value('passed_at');

    $this->actingAs($user)->postJson(
        route('quizzes.attempts.store', [$course, $quiz]),
        ['answers' => []],
    );

    $progress = UserQuizProgress::query()
        ->where('user_id', $user->id)
        ->where('quiz_id', $quiz->id)
        ->firstOrFail();

    $this->assertNotNull($progress->passed_at);
    $this->assertEquals($passedAt, $progress->passed_at);
    $this->assertSame(QuizStatus::Passed, $progress->status());
});

test('every submission writes its own attempt row', function (): void {
    [$course, $quiz] = publishedQuiz();
    $user = User::factory()->create();

    foreach ([[], correctAnswers($quiz), []] as $answers) {
        $this->actingAs($user)->postJson(
            route('quizzes.attempts.store', [$course, $quiz]),
            ['answers' => $answers],
        )->assertOk();
    }

    $this->assertSame(3, QuizAttempt::query()->where('user_id', $user->id)->count());
    $this->assertSame([0, 100, 0], QuizAttempt::query()
        ->where('user_id', $user->id)
        ->orderBy('id')
        ->pluck('score')
        ->all());
});

test('the pass mark is the quizzes own', function (): void {
    $course = Course::factory()->create();
    $quiz = Quiz::factory()->for($course)->passingAt(50)->withQuestions()->create();

    // Two of four is 50% — exactly the bar, and the bar is inclusive.
    $answers = array_slice(correctAnswers($quiz), 0, 2, true);

    $this->actingAs(User::factory()->create())
        ->postJson(route('quizzes.attempts.store', [$course, $quiz]), ['answers' => $answers])
        ->assertJson(['score' => 50, 'passed' => true]);
});

test('a quiz with no questions cannot be passed', function (): void {
    $course = Course::factory()->create();
    $quiz = Quiz::factory()->for($course)->create();

    $this->actingAs(User::factory()->create())
        ->postJson(route('quizzes.attempts.store', [$course, $quiz]), ['answers' => []])
        ->assertOk()
        ->assertJson(['score' => 0, 'questionCount' => 0, 'passed' => false]);
});

test('the course page lists its quizzes with the viewers progress', function (): void {
    [$course, $quiz] = publishedQuiz();
    $user = User::factory()->create();

    UserQuizProgress::factory()->passed(90)->create([
        'user_id' => $user->id,
        'quiz_id' => $quiz->id,
    ]);

    Quiz::factory()->for($course)->unpublished()->create();

    $this->actingAs($user)
        ->get(route('courses.show', $course))
        ->assertInertia(fn ($page) => $page
            ->missing('quizzes')
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('quizzes', 1)
                ->where('quizzes.0.slug', $quiz->slug)
                ->where('quizzes.0.questionCount', 4)
                ->where('quizzes.0.locked', false)
                ->where('quizzes.0.status', QuizStatus::Passed->value)
                ->where('quizzes.0.bestScore', 90)
                ->where('quizzes.0.passed', true)));
});

test('a locked quiz is listed as locked rather than hidden', function (): void {
    $course = Course::factory()->create();
    $quiz = Quiz::factory()->for($course)->requiring(Plan::Pro)->withQuestions()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('courses.show', $course))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->has('quizzes', 1)
                ->where('quizzes.0.slug', $quiz->slug)
                ->where('quizzes.0.locked', true)
                ->where('quizzes.0.requiredPlan', Plan::Pro->value)
                // The blurb is the advertisement and is not what is sold.
                ->where('quizzes.0.description', $quiz->description)));
});

/**
 * A published quiz with four single-answer questions on a published
 * course.
 *
 * @return array{0: Course, 1: Quiz}
 */
function publishedQuiz(): array
{
    $course = Course::factory()->create();
    $quiz = Quiz::factory()->for($course)->withQuestions()->create();

    return [$course, $quiz];
}

/**
 * The answer key, in the shape the endpoint accepts.
 *
 * @return array<int, array<int, int>>
 */
function correctAnswers(Quiz $quiz): array
{
    return $quiz->questions()
        ->with('options')
        ->get()
        ->mapWithKeys(fn (QuizQuestion $question): array => [
            $question->id => $question->correctOptionIds(),
        ])
        ->all();
}
