<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;

beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

test('email verification screen can be rendered', function (): void {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    $response->assertOk();
});

test('email can be verified', function (): void {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $response = $this->actingAs($user)->get($verificationUrl);

    Event::assertDispatched(Verified::class);

    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
    $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
});

test('email is not verified with invalid hash', function (): void {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1('wrong-email')],
    );

    $this->actingAs($user)->get($verificationUrl);

    Event::assertNotDispatched(Verified::class);
    expect($user->refresh()->hasVerifiedEmail())->toBeFalse();
});

test('email is not verified with invalid user id', function (): void {
    $user = User::factory()->unverified()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => 123, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl);

    Event::assertNotDispatched(Verified::class);
    expect($user->refresh()->hasVerifiedEmail())->toBeFalse();
});

test('verified user is redirected to dashboard from verification prompt', function (): void {
    $user = User::factory()->create();

    Event::fake();

    $response = $this->actingAs($user)->get(route('verification.notice'));

    Event::assertNotDispatched(Verified::class);
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('already verified user visiting verification link is redirected without firing event again', function (): void {
    $user = User::factory()->create();

    Event::fake();

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    $this->actingAs($user)->get($verificationUrl)
        ->assertRedirect(route('dashboard', absolute: false).'?verified=1');

    Event::assertNotDispatched(Verified::class);
    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
});

/**
 * The contract itself, asserted directly.
 *
 * Everything below this point depends on it and none of it would fail in a
 * way that names it: Illuminate\Auth\Middleware\EnsureEmailIsVerified and
 * Laravel's SendEmailVerificationNotification listener both gate on
 * `instanceof MustVerifyEmail`, so dropping the interface turns the whole
 * feature off silently rather than breaking anything that mentions it.
 * That is exactly how it came to be missing while the routes, the Fortify
 * feature and every screen in this file were all present and passing.
 */
arch('user model implements the must verify email contract')
    ->expect(User::class)
    ->toImplement(MustVerifyEmail::class);

test('unverified user cannot reach a verified route', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

test('verified user reaches a verified route', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

/**
 * Registration has to actually mail the link, or enforcing verification
 * just locks new pilots out of an account they cannot open.
 */
test('registration sends a verification notification', function (): void {
    $this->skipUnlessFortifyHas(Features::registration());

    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'Test Pilot',
        'email' => 'pilot@example.com',
        'password' => 'Password1!Password1!',
        'password_confirmation' => 'Password1!Password1!',
    ]);

    $user = User::query()->where('email', 'pilot@example.com')->firstOrFail();

    expect($user->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmail::class);
});

/**
 * App\Http\Controllers\Settings\ProfileController clears
 * `email_verified_at` when the address changes. This is the half of that
 * control that has to hold for it to mean anything — until the contract
 * landed, the column was cleared and access continued regardless.
 */
test('changing email address revokes access until reverified', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'moved@example.com',
        ]);

    expect($user->refresh()->email_verified_at)->toBeNull();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});
