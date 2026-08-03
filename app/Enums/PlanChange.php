<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What came of asking to move a subscription onto another plan.
 *
 * Three outcomes rather than a boolean, because each is a different sentence to
 * the pilot and two of them are not failures. A swap that changed nothing is the
 * ordinary answer to submitting the form without touching it. A plan that cannot
 * be moved to is our configuration's problem rather than theirs. Only a thrown
 * exception means Lemon Squeezy refused the change itself.
 *
 * Not backed by a string: nothing persists or transmits one of these, and giving
 * them values would invite a controller to hand one to the client in place of
 * the message it owes them.
 */
enum PlanChange
{
    /** The subscription now sells a different price than it did. */
    case Swapped;

    /** It was already on exactly that plan and billing period. */
    case Unchanged;

    /**
     * Nothing can move to that plan: a sales-led tier, a billing period it does
     * not sell, or a price or product this environment has not configured.
     */
    case Unavailable;
}
