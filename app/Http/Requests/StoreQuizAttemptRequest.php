<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Quiz;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A submitted quiz, as answered.
 *
 * Note what this does not accept: a score, a correct count, or a claim of
 * passing. The browser reports which options were ticked;
 * {@see \App\Actions\GradeQuizSubmission} reads the answer key and decides
 * what that was worth. Same rule as {@see StoreChallengeAttemptRequest}, for
 * the same reason — a client that can post its own result is not being graded.
 */
final class StoreQuizAttemptRequest extends FormRequest
{
    /**
     * Ceiling on how many questions one submission may answer.
     *
     * Far above any authored quiz; it exists to bound what a script can post,
     * not to limit an author. A submission naming more questions than this is
     * not a quiz being taken.
     */
    private const int MAX_ANSWERED_QUESTIONS = 200;

    /**
     * The submission, keyed by question id and reduced to distinct ids,
     * built during validation.
     *
     * Checking an answer and casting it are the same walk over the same data,
     * so the walk happens once and {@see self::answers()} is handed what it
     * produced — the same arrangement {@see StoreChallengeAttemptRequest}
     * uses for its flight path.
     *
     * @var array<int, array<int, int>>|null
     */
    private ?array $answers = null;

    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization for this endpoint is publication and plan, both of which
     * need the course as well as the quiz and are asserted in the controller
     * alongside the identical guards on the page that precedes it. Splitting
     * one of the two checks out to here would leave the pair looking like it
     * was enforced in one place when it was not.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The answers are checked by one closure over the whole array rather than
     * by `answers.*.*` rules. Every option id has to be checked against this
     * quiz's own questions regardless — an id is only meaningful relative to
     * the question it belongs to — so a wildcard rule would build the same
     * lookup per key that the closure builds once, on top of expanding a rule
     * object per selected option. That is the reasoning
     * {@see StoreChallengeAttemptRequest} records at length for its path, and
     * it applies here for the same reason.
     *
     * `present` rather than `required`: a pilot who answers nothing has made
     * a submission, and it scores zero. An empty array is that submission;
     * a missing key is a malformed request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'answers' => [
                'bail', 'present', 'array', 'max:'.self::MAX_ANSWERED_QUESTIONS,
                $this->validateAnswers(...),
            ],
        ];
    }

    /**
     * The validated submission: selected option ids, keyed by question id.
     *
     * Questions the pilot left blank are simply absent. The grader counts
     * them as wrong rather than as excused, so there is nothing to fill in
     * here.
     *
     * @return array<int, array<int, int>>
     */
    public function answers(): array
    {
        return $this->answers ?? [];
    }

    /**
     * Check that every answer names a question on this quiz and options on
     * that question.
     *
     * Both failures describe a client that is not the quiz page: the ids were
     * handed to it by {@see \App\Http\Resources\QuizDetailResource} moments
     * earlier. The one honest way a pilot reaches this is an author editing
     * the quiz while it is open in their browser, which is why the message
     * asks them to reload rather than blaming the answer.
     *
     * Silently dropping unknown ids was the alternative and is worse: it
     * would grade a stale page against a changed quiz and report the
     * resulting zero as the pilot's own doing.
     *
     * @param  array<mixed>  $value
     */
    private function validateAnswers(string $attribute, mixed $value, Closure $fail): void
    {
        $optionIdsByQuestion = $this->optionIdsByQuestion();
        $answers = [];

        foreach ($value as $questionId => $selection) {
            if (! is_numeric($questionId) || ! array_key_exists((int) $questionId, $optionIdsByQuestion)) {
                $fail('This submission answers a question that is not on this quiz. Reload the page and try again.');

                return;
            }

            if (! is_array($selection)) {
                $fail('An answer was not a list of choices.');

                return;
            }

            $allowed = $optionIdsByQuestion[(int) $questionId];
            $selected = [];

            foreach ($selection as $optionId) {
                if (! is_numeric($optionId) || ! in_array((int) $optionId, $allowed, true)) {
                    $fail('This submission chose an answer that is not on this quiz. Reload the page and try again.');

                    return;
                }

                $selected[] = (int) $optionId;
            }

            $answers[(int) $questionId] = array_values(array_unique($selected));
        }

        $this->answers = $answers;
    }

    /**
     * Every option id this quiz will accept, grouped by the question it
     * belongs to.
     *
     * One query for the whole submission. Grouping by question is what makes
     * the check meaningful: an option id that exists on a *different* question
     * of the same quiz is still not an answer to this one, and a flat list of
     * valid ids would let a client mix them.
     *
     * @return array<int, array<int, int>>
     */
    private function optionIdsByQuestion(): array
    {
        $quiz = $this->route('quiz');

        if (! $quiz instanceof Quiz) {
            return [];
        }

        return $quiz->questions()
            ->with('options:id,quiz_question_id')
            ->get(['id', 'quiz_id'])
            ->mapWithKeys(fn (QuizQuestion $question): array => [
                $question->id => $question->options
                    ->map(fn (QuizOption $option): int => $option->id)
                    ->all(),
            ])
            ->all();
    }
}
