<?php

declare(strict_types=1);

namespace App\Actions;

use App\Http\Integrations\Kelviq;
use App\Models\DronePhoto;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Close somebody's account from the admin area.
 *
 * Everything the pilot owns cascades with the row — progress, runs, attempts,
 * photos. Two things do not, and this is where they are dealt with.
 *
 * A subscription Kelviq is still billing. Deleting the account here would leave
 * the card being charged for an account that no longer exists to use it. That
 * is refused rather than attempted: cancelling is the pilot's to do in the
 * Kelviq portal, or staff's in the Kelviq dashboard, and it should be seen to
 * end before the only record of who it belonged to is removed. A subscription
 * already scheduled to end bills nothing more, so it does not stand in the way,
 * and neither does a lifetime purchase, which was paid for once and never
 * bills again.
 *
 * The photo files. The rows cascade at the database level and the files on
 * the photo disk do not, so their paths are read before the delete and the
 * files removed after it — the order DronePhotoController uses, for its
 * reason: a failed file delete leaves a stray file rather than a log entry
 * pointing at nothing.
 */
final readonly class DeleteUser
{
    /** The statuses Kelviq goes on charging a card in. */
    private const array BILLING_STATUSES = ['active', 'trialing', 'past_due'];

    public function __construct(private Kelviq $kelviq)
    {
        //
    }

    /**
     * False when the account is still being billed, and nothing was deleted.
     *
     * Throws when Kelviq cannot say, which is not the same as "no": an account
     * whose billing cannot be checked is not deleted.
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

    /**
     * @throws ConnectionException|RequestException
     */
    private function isStillBilling(User $user): bool
    {
        if (! $this->kelviq->configured()) {
            return false;
        }

        try {
            $subscriptions = $this->kelviq->listSubscriptions($user->uuid);
        } catch (RequestException $requestException) {
            /*
             * A customer Kelviq has never heard of has nothing to bill.
             */
            if ($requestException->response->notFound()) {
                return false;
            }

            throw $requestException;
        }

        return array_any($subscriptions, static fn (array $subscription): bool => in_array(mb_strtolower(is_string($subscription['status'] ?? null) ? $subscription['status'] : ''), self::BILLING_STATUSES, true)
            && ($subscription['endDate'] ?? null) === null
            && ($subscription['billingType'] ?? null) !== 'ONE_TIME');
    }
}
