<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\DronePhoto;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Close somebody's account from the admin area.
 *
 * Everything the pilot owns cascades with the row — progress, runs, attempts,
 * photos, the local copy of their billing. Two things do not, and this is
 * where they are dealt with.
 *
 * A subscription Creem is still billing. Deleting the account here removes
 * the copy and not the original, so the card would go on being charged for an
 * account that no longer exists to use it. That is refused rather than
 * attempted: cancelling is a live call to Creem with its own failure modes,
 * and the person closing the account should see the subscription end before
 * they remove the only record of who it belonged to. A subscription already
 * winding down bills nothing more, so it does not stand in the way.
 *
 * The photo files. The rows cascade at the database level and the files on
 * the photo disk do not, so their paths are read before the delete and the
 * files removed after it — the order DronePhotoController uses, for its
 * reason: a failed file delete leaves a stray file rather than a log entry
 * pointing at nothing.
 */
final readonly class DeleteUser
{
    /**
     * False when the account is still being billed, and nothing was deleted.
     *
     * @throws Throwable
     */
    public function handle(User $user): bool
    {
        if ($this->isStillBilling($user)) {
            return false;
        }

        $paths = DronePhoto::query()->where('user_id', $user->id)->pluck('path')->all();

        DB::transaction(fn (): ?bool => $user->delete());

        if ($paths !== []) {
            DronePhoto::disk()->delete($paths);
        }

        return true;
    }

    private function isStillBilling(User $user): bool
    {
        return Subscription::query()
            ->whereMorphedTo('billable', $user)
            ->get()
            ->contains(fn (Subscription $subscription): bool => $subscription->valid() && ! $subscription->cancelled());
    }
}
