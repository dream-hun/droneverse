<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A pilot's standing on one quiz.
 *
 * Deliberately not {@see ChallengeStatus}, despite the near-identical shape. A
 * mission is flown over many partial runs and `InProgress` is a real state a
 * pilot sits in; a quiz is submitted whole, so the middle state is not "part
 * way through" but "tried and did not reach the pass mark". Sharing the enum
 * would have forced that answer into a word that means something else, and
 * every screen reading it would have had to know which of the two it was
 * looking at.
 */
enum QuizStatus: string
{
    case NotStarted = 'not_started';
    case Attempted = 'attempted';
    case Passed = 'passed';
}
