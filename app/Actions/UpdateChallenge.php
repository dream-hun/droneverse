<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Challenge;

/**
 * Edit a mission's briefing, code, world or grading rules.
 *
 * Saved through the model, never a query-builder update, because publishing
 * or pulling a mission changes totals only App\Observers\ChallengeObserver
 * knows to rebuild — and it only hears about writes that go through Eloquent.
 *
 * Changing the success criteria does not re-grade anybody. A pilot's best
 * score was earned against the rules of the day and stands; the new rules
 * apply from the next run.
 */
final readonly class UpdateChallenge
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Challenge $challenge, array $attributes): Challenge
    {
        $challenge->update($attributes);

        return $challenge;
    }
}
