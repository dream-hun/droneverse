<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/*
 * The grandfathering rule in the email-verification backfill.
 *
 * The migration has already run by the time these start — it is written to be
 * safe to re-run, which is what lets it be tested at all — so each case seeds
 * the state it cares about and runs `up()` again over it.
 */

/**
 * Comfortably before the cutoff baked into the migration.
 */
const BEFORE_CUTOFF = '2026-07-01 12:00:00';

/**
 * Comfortably after it — an account the new code created and mailed.
 */
const AFTER_CUTOFF = '2026-08-04 12:00:00';

test('it grandfathers accounts that existed before enforcement', function (): void {
    $user = User::factory()->unverified()->create([
        'created_at' => BEFORE_CUTOFF,
    ]);

    runBackfill();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

/**
 * The case the fixed cutoff exists for: someone who registered in the
 * window between the migration and the deploy, was sent a verification
 * link, and must still be required to use it.
 */
test('it leaves accounts created after enforcement unverified', function (): void {
    $user = User::factory()->unverified()->create([
        'created_at' => AFTER_CUTOFF,
    ]);

    runBackfill();

    expect($user->refresh()->email_verified_at)->toBeNull();
});

test('it grandfathers accounts with no created at', function (): void {
    $user = User::factory()->unverified()->create([
        'created_at' => null,
    ]);

    runBackfill();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

/**
 * Re-running must not rewrite when somebody actually verified — which is
 * also what makes the rest of this file able to run `up()` at all.
 */
test('it does not overwrite an existing verification timestamp', function (): void {
    $verifiedAt = now()->subYear()->startOfSecond();

    $user = User::factory()->create([
        'created_at' => BEFORE_CUTOFF,
        'email_verified_at' => $verifiedAt,
    ]);

    runBackfill();

    expect($verifiedAt->equalTo($user->refresh()->email_verified_at))->toBeTrue();
});

function runBackfill(): void
{
    $migration = require database_path(
        'migrations/2026_08_03_230022_backfill_email_verified_at_for_pre_verification_users.php',
    );

    expect($migration)->toBeInstanceOf(Migration::class);

    $migration->up();
}
