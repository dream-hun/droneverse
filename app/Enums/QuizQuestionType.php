<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How many of a question's options may be chosen.
 *
 * The distinction is the grader's, not the author's: a question is scored
 * against the exact set of options marked correct, and the type is what tells
 * the pilot — and the input widget — whether picking a second answer replaces
 * the first or adds to it. Getting that wrong is not a styling bug; a pilot
 * shown radio buttons on a question with two correct answers cannot pass it.
 *
 * True/false is deliberately not a case. It is a Single question with two
 * options, it grades identically, and giving it a case of its own would add a
 * branch to the grader that never behaves differently.
 */
enum QuizQuestionType: string
{
    case Single = 'single';
    case Multiple = 'multiple';

    /**
     * The type a question has when this many of its options are correct.
     *
     * The admin editor derives the type rather than asking for it, because the
     * two can only ever disagree in one direction and both are wrong: a single
     * question with two right answers renders radio buttons and cannot be
     * passed, and a multiple question with one right answer tells the pilot
     * there is more than one. The answer key is the authority, so the type
     * follows it.
     */
    public static function forCorrectAnswers(int $count): self
    {
        return $count > 1 ? self::Multiple : self::Single;
    }

    /**
     * Whether more than one option may be selected.
     */
    public function allowsMultipleAnswers(): bool
    {
        return $this === self::Multiple;
    }
}
