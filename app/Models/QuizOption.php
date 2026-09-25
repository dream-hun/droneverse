<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\QuizOptionFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One selectable answer.
 *
 * `is_correct` is the answer key, and it is hidden for the same reason
 * {@see Challenge} hides `solution_code`: the shape that reaches the browser
 * is built by hand in {@see \App\Http\Resources\QuizDetailResource}, and this
 * attribute is the backstop for every path that is not that one. A future
 * `toArray()`, a debug dump, an eager-loaded relation serialized by accident
 * — none of them can hand a pilot the means to grade themselves.
 *
 * @property int $id
 * @property int $quiz_question_id
 * @property string $label
 * @property bool $is_correct
 * @property int $order
 */
#[Hidden(['is_correct'])]
final class QuizOption extends Model
{
    /** @use HasFactory<QuizOptionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<QuizQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(QuizQuestion::class, 'quiz_question_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'order' => 'integer',
        ];
    }
}
