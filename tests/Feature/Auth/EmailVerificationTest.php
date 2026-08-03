<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Features;
use Tests\TestCase;

final class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::emailVerification());
    }

    public function test_email_verification_screen_can_be_rendered(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get(route('verification.notice'));

        $response->assertOk();
    }

    public function test_email_can_be_verified(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
    }

    public function test_email_is_not_verified_with_invalid_hash(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')],
        );

        $this->actingAs($user)->get($verificationUrl);

        Event::assertNotDispatched(Verified::class);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_email_is_not_verified_with_invalid_user_id(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => 123, 'hash' => sha1($user->email)],
        );

        $this->actingAs($user)->get($verificationUrl);

        Event::assertNotDispatched(Verified::class);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verified_user_is_redirected_to_dashboard_from_verification_prompt(): void
    {
        $user = User::factory()->create();

        Event::fake();

        $response = $this->actingAs($user)->get(route('verification.notice'));

        Event::assertNotDispatched(Verified::class);
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_already_verified_user_visiting_verification_link_is_redirected_without_firing_event_again(): void
    {
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
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

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
    public function test_user_model_implements_the_must_verify_email_contract(): void
    {
        $this->assertInstanceOf(MustVerifyEmail::class, User::factory()->create());
    }

    public function test_unverified_user_cannot_reach_a_verified_route(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_verified_user_reaches_a_verified_route(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();
    }

    /**
     * Registration has to actually mail the link, or enforcing verification
     * just locks new pilots out of an account they cannot open.
     */
    public function test_registration_sends_a_verification_notification(): void
    {
        $this->skipUnlessFortifyHas(Features::registration());

        Notification::fake();

        $this->post(route('register.store'), [
            'name' => 'Test Pilot',
            'email' => 'pilot@example.com',
            'password' => 'Password1!Password1!',
            'password_confirmation' => 'Password1!Password1!',
        ]);

        $user = User::query()->where('email', 'pilot@example.com')->firstOrFail();

        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    /**
     * App\Http\Controllers\Settings\ProfileController clears
     * `email_verified_at` when the address changes. This is the half of that
     * control that has to hold for it to mean anything — until the contract
     * landed, the column was cleared and access continued regardless.
     */
    public function test_changing_email_address_revokes_access_until_reverified(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'moved@example.com',
            ]);

        $this->assertNull($user->refresh()->email_verified_at);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('verification.notice'));
    }
}
