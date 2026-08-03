<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The grandfathering rule in the email-verification backfill.
 *
 * The migration has already run by the time these start — it is written to be
 * safe to re-run, which is what lets it be tested at all — so each case seeds
 * the state it cares about and runs `up()` again over it.
 */
final class BackfillEmailVerifiedAtTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Comfortably before the cutoff baked into the migration.
     */
    private const string BEFORE_CUTOFF = '2026-07-01 12:00:00';

    /**
     * Comfortably after it — an account the new code created and mailed.
     */
    private const string AFTER_CUTOFF = '2026-08-04 12:00:00';

    public function test_it_grandfathers_accounts_that_existed_before_enforcement(): void
    {
        $user = User::factory()->unverified()->create([
            'created_at' => self::BEFORE_CUTOFF,
        ]);

        $this->runBackfill();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    /**
     * The case the fixed cutoff exists for: someone who registered in the
     * window between the migration and the deploy, was sent a verification
     * link, and must still be required to use it.
     */
    public function test_it_leaves_accounts_created_after_enforcement_unverified(): void
    {
        $user = User::factory()->unverified()->create([
            'created_at' => self::AFTER_CUTOFF,
        ]);

        $this->runBackfill();

        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_it_grandfathers_accounts_with_no_created_at(): void
    {
        $user = User::factory()->unverified()->create([
            'created_at' => null,
        ]);

        $this->runBackfill();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    /**
     * Re-running must not rewrite when somebody actually verified — which is
     * also what makes the rest of this file able to run `up()` at all.
     */
    public function test_it_does_not_overwrite_an_existing_verification_timestamp(): void
    {
        $verifiedAt = now()->subYear()->startOfSecond();

        $user = User::factory()->create([
            'created_at' => self::BEFORE_CUTOFF,
            'email_verified_at' => $verifiedAt,
        ]);

        $this->runBackfill();

        $this->assertTrue($verifiedAt->equalTo($user->refresh()->email_verified_at));
    }

    private function runBackfill(): void
    {
        $migration = require database_path(
            'migrations/2026_08_03_230022_backfill_email_verified_at_for_pre_verification_users.php',
        );

        $this->assertInstanceOf(Migration::class, $migration);

        $migration->up();
    }
}
