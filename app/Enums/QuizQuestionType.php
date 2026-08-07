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
     * Whether more than one option may be selected.
     */
    public function allowsMultipleAnswers(): bool
    {
        return $this === self::Multiple;
    }
}
