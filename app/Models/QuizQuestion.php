<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QuizQuestionType;
use Database\Factories\QuizQuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One question on a quiz, and the authority on what answers it.
 *
 * @property int $id
 * @property int $quiz_id
 * @property string $prompt
 * @property QuizQuestionType $type
 * @property string|null $explanation
 * @property int $order
 * @property-read Collection<int, QuizOption> $options
 */
#[Fillable(['quiz_id', 'prompt', 'type', 'explanation', 'order'])]
final class QuizQuestion extends Model
{
    /** @use HasFactory<QuizQuestionFactory> */
    use HasFactory;

    /**
     * The ids of the options that make this question correct.
     *
     * Reads `is_correct` off the loaded options rather than querying, because
     * every caller has already eager-loaded them — {@see \App\Actions\GradeQuizSubmission}
     * grades a whole quiz in one pass and must not fire a query per question
     * to do it.
     *
     * @return array<int, int>
     */
    public function correctOptionIds(): array
    {
        return $this->options
            ->filter(fn (QuizOption $option): bool => $option->is_correct)
            ->map(fn (QuizOption $option): int => $option->id)
            ->values()
            ->all();
    }

    /**
     * Whether the given selection answers this question.
     *
     * All-or-nothing, on both kinds of question. A `multiple` question is
     * correct when the chosen set is exactly the correct set — no partial
     * credit for finding one of two right answers, and no credit at all for
     * selecting everything. Partial credit sounds generous and is not: it
     * rewards a pilot for shotgunning every option, which is the one answering
     * strategy a knowledge check exists to rule out.
     *
     * The comparison is order-insensitive and duplicate-insensitive because
     * the payload is a list of checkbox values, and neither the order the
     * pilot ticked them in nor a repeated id says anything about what they
     * know. {@see \App\Http\Requests\StoreQuizAttemptRequest} has already
     * reduced the selection to distinct ids that belong to this question, so
     * what arrives here cannot claim an option from a different quiz.
     *
     * @param  array<int, int>  $selectedOptionIds
     */
    public function isAnsweredBy(array $selectedOptionIds): bool
    {
        $correct = $this->correctOptionIds();
        $selected = array_values(array_unique($selectedOptionIds));

        // A question nobody marked an answer for is unanswerable, not free.
        // Treating it as correct would hand a point to every pilot for an
        // authoring mistake; this way it simply cannot be earned.
        if ($correct === []) {
            return false;
        }

        sort($correct);
        sort($selected);

        return $correct === $selected;
    }

    /**
     * @return BelongsTo<Quiz, $this>
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * @return HasMany<QuizOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(QuizOption::class)->orderBy('order');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => QuizQuestionType::class,
            'order' => 'integer',
        ];
    }
}
