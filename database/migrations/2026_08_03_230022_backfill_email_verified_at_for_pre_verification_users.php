<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The moment email verification started being enforced.
     *
     * Fixed rather than `now()`, and that is the whole point of it. The
     * backfill and the deploy that makes App\Models\User implement
     * MustVerifyEmail cannot land at the same instant, and whichever order they
     * land in there is a window between them. `now()` would sweep up anyone who
     * registered inside that window — accounts created by the *new* code, which
     * mailed them a verification link and is entitled to wait for it — and mark
     * them verified without them ever clicking anything. A fixed cutoff cannot:
     * it names accounts that existed before the rule did, and no later
     * registration can drift into it however long the deploy takes.
     *
     * It also makes the migration safe to re-run, and safe to run against a
     * database restored from a backup taken after the deploy.
     */
    private const string ENFORCEMENT_STARTED_AT = '2026-08-03 23:00:22';

    /**
     * Grandfather every account that existed before verification was enforced.
     *
     * `verified` has been on the routes since they were written, but nothing
     * behind it ever ran: the middleware gates on the MustVerifyEmail contract
     * and the model did not implement it, so no account was ever asked to
     * verify and no verification mail was ever sent on registration. Turning
     * the contract on turns all of that on at once, and every row still sitting
     * at `email_verified_at IS NULL` is a user who was never given the chance
     * to fill it in.
     *
     * Locking those people out of a platform they have been using — some of
     * them paying for — to enforce a step we never asked them to take is not a
     * security improvement, so they are grandfathered. The address is no less
     * proven than it was yesterday; what changes is that everyone arriving from
     * here on has to prove theirs.
     *
     * This must ship in the same deployment as the contract. Any gap is a
     * window in which existing users are bounced to the verification screen
     * with no mail to act on.
     *
     * Rows that already carry a timestamp are untouched, so re-running this
     * cannot rewrite when somebody actually verified.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->where(function (Builder $query): void {
                // A row predating `timestamps()` has no created_at to compare;
                // it is unambiguously older than the cutoff either way.
                $query->where('created_at', '<', self::ENFORCEMENT_STARTED_AT)
                    ->orWhereNull('created_at');
            })
            ->update(['email_verified_at' => now()]);
    }
};
