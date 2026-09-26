<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Auth\Events\Verified;

/**
 * Vouch for an account's email address on the pilot's behalf.
 *
 * For the pilot whose verification mail never arrived, confirmed some other
 * way — a support thread from that address, a school's roster. The same event
 * fires as when the pilot clicks the link, so anything listening for a
 * verified account hears about this one too.
 */
final readonly class VerifyUserEmail
{
    /**
     * False when the address was already verified, and nothing changed.
     */
    public function handle(User $user): bool
    {
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        $user->markEmailAsVerified();

        event(new Verified($user));

        return true;
    }
}
