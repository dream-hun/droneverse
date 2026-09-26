<?php

declare(strict_types=1);

use App\Enums\QuizQuestionType;
use App\Models\Course;
use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function quizPayload(array $overrides = []): array
{
    return [
        'title' => 'Final Check',
        'slug' => 'final-check',
        'description' => 'What the course taught.',
        'order' => 0,
        'required_plan' => null,
        'pass_percentage' => 80,
        ...$overrides,
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $options
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function questionPayload(array $options, array $overrides = []): array
{
    return [
        'prompt' => 'Which call lifts the drone?',
        'explanation' => 'takeoff() climbs to the default altitude.',
        'order' => 0,
        'options' => $options,
        ...$overrides,
    ];
}

test('creates an unpublished quiz and opens it for questions', function (): void {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.courses.quizzes.store', $course), quizPayload())
        ->assertRedirect(route('admin.courses.quizzes.show', [$course, 'final-check']));

    $quiz = $course->quizzes()->sole();

    expect($quiz->is_published)->toBeFalse()
        ->and($quiz->pass_percentage)->toBe(80);
});

test('a pass mark of zero is refused', function (): void {
    $admin = User::factory()->admin()->create();
    $course = Course::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.courses.quizzes.store', $course), quizPayload(['pass_percentage' => 0]))
        ->assertSessionHasErrors('pass_percentage');
});

test('a quiz slug need only be unique within its course', function (): void {
    $admin = User::factory()->admin()->create();
    Quiz::factory()->create(['slug' => 'final-check']);
    $course = Course::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.courses.quizzes.store', $course), quizPayload())
        ->assertSessionHasNoErrors();
});

test('the quiz page carries the answer key', function (): void {
    $admin = User::factory()->admin()->create();
    $quiz = Quiz::factory()->create();
    QuizQuestion::factory()->for($quiz)->withOptions(correct: 1)->create();

    $this->actingAs($admin)
        ->get(route('admin.courses.quizzes.show', [$quiz->course, $quiz]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('admin/quizzes/show')
            ->has('questions.0.options', 4)
            ->where('questions.0.options.0.isCorrect', true)
            ->where('questions.0.options.1.isCorrect', false));
});

test('a quiz named under the wrong course is a 404', function (): void {
    $admin = User::factory()->admin()->create();
    $quiz = Quiz::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.courses.quizzes.show', [Course::factory()->create(), $quiz]))
        ->assertNotFound();
});

test('two correct answers make a pick-all question', function (): void {
    $admin = User::factory()->admin()->create();
    $quiz = Quiz::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.courses.quizzes.questions.store', [$quiz->course, $quiz]), questionPayload([
            ['label' => 'takeoff()', 'is_correct' => '1'],
            ['label' => 'ascend()', 'is_correct' => '1'],
            ['label' => 'land()'],
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.courses.quizzes.show', [$quiz->course, $quiz]));

    $question = $quiz->questions()->with('options')->sole();

    expect($question->type)->toBe(QuizQuestionType::Multiple)
        ->and($question->options->pluck('label')->all())->toBe(['takeoff()', 'ascend()', 'land()'])
        ->and($question->correctOptionIds())->toHaveCount(2);
});

test('a question needs a correct answer', function (): void {
    $admin = User::factory()->admin()->create();
    $quiz = Quiz::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.courses.quizzes.questions.store', [$quiz->course, $quiz]), questionPayload([
            ['label' => 'takeoff()'],
            ['label' => 'land()'],
        ]))
        ->assertSessionHasErrors(['options' => 'Mark at least one answer as correct.']);

    expect($quiz->questions()->exists())->toBeFalse();
});

test('a question needs two answers to choose between', function (): void {
    $admin = User::factory()->admin()->create();
    $quiz = Quiz::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.courses.quizzes.questions.store', [$quiz->course, $quiz]), questionPayload([
            ['label' => 'takeoff()', 'is_correct' => '1'],
        ]))
        ->assertSessionHasErrors(['options' => 'Give at least two answers to choose between.']);
});

test('editing keeps the answers it was given and drops the rest', function (): void {
    $admin = User::factory()->admin()->create();
    $quiz = Quiz::factory()->create();
    $question = QuizQuestion::factory()->for($quiz)->multiple()->withOptions(correct: 2)->create();
    [$first, $second] = $question->options()->get()->all();
    $foreign = QuizOption::factory()->create();

    $this->actingAs($admin)
        ->put(route('admin.courses.quizzes.questions.update', [$quiz->course, $quiz, $question]), questionPayload([
            ['id' => $first->id, 'label' => 'Corrected label', 'is_correct' => '1'],
            ['id' => $second->id, 'label' => $second->label],
            ['id' => $foreign->id, 'label' => 'Brand new'],
        ]))
        ->assertSessionHasNoErrors();

    $options = $question->options()->get();

    // The two kept answers are updated in place, so a pilot mid-quiz still
    // holds ids that count; the foreign id is not borrowed from its question.
    expect($options->pluck('id')->take(2)->all())->toBe([$first->id, $second->id])
        ->and($options->count())->toBe(3)
        ->and($options->get(0)?->label)->toBe('Corrected label')
        ->and($options->get(2)?->id)->not->toBe($foreign->id)
        ->and($foreign->fresh()?->quiz_question_id)->toBe($foreign->quiz_question_id)
        ->and($foreign->quiz_question_id)->not->toBe($question->id)
        ->and($question->fresh()?->type)->toBe(QuizQuestionType::Single);
});

test('a question named under the wrong quiz is a 404', function (): void {
    $admin = User::factory()->admin()->create();
    $question = QuizQuestion::factory()->withOptions()->create();
    $otherQuiz = Quiz::factory()->create();

    $this->actingAs($admin)
        ->delete(route('admin.courses.quizzes.questions.destroy', [$otherQuiz->course, $otherQuiz, $question]))
        ->assertNotFound();

    $this->assertModelExists($question);
});

test('deleting a quiz returns to its course', function (): void {
    $admin = User::factory()->admin()->create();
    $quiz = Quiz::factory()->create();

    $this->actingAs($admin)
        ->delete(route('admin.courses.quizzes.destroy', [$quiz->course, $quiz]))
        ->assertRedirect(route('admin.courses.show', $quiz->course));

    $this->assertModelMissing($quiz);
});

test('the course page lists its quizzes', function (): void {
    $admin = User::factory()->admin()->create();
    $quiz = Quiz::factory()->create();
    QuizQuestion::factory()->for($quiz)->count(3)->create();

    $this->actingAs($admin)
        ->get(route('admin.courses.show', $quiz->course))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('quizzes.0.slug', $quiz->slug)
            ->where('quizzes.0.questions', 3));
});
