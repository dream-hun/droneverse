<?php

declare(strict_types=1);

use App\Enums\AdminPermission;
use App\Enums\Plan;
use App\Models\Challenge;
use App\Models\ChallengeRun;
use App\Models\Course;
use App\Models\DronePhoto;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

describe('index', function (): void {
    test('searches accounts by name or address', function (): void {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'Ada Lovelace']);
        User::factory()->create(['name' => 'Grace Hopper']);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['q' => 'lovelace']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('admin/users/index')
                ->has('users', 1)
                ->where('users.0.name', 'Ada Lovelace'));
    });

    test('narrows to staff', function (): void {
        $admin = User::factory()->admin()->create();
        User::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['role' => 'staff']))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->has('users', 1)
                ->where('users.0.uuid', $admin->uuid));
    });

    test('reports the resolved plan beside the hand-set one', function (): void {
        $admin = User::factory()->admin()->create(['created_at' => now()->subDay()]);
        User::factory()->onPlan(Plan::Pro)->create();

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('users.0.plan.value', 'pro')
                ->where('users.0.planOverride', 'pro'));
    });
});

describe('store', function (): void {
    test('opens an unverified account with a hand-set plan', function (): void {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'New Pilot',
                'email' => 'new@example.com',
                'password' => 'password-123',
                'password_confirmation' => 'password-123',
                'plan_override' => 'pro',
            ])
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'success');

        $user = User::query()->where('email', 'new@example.com')->sole();

        expect($user->email_verified_at)->toBeNull()
            ->and($user->plan())->toBe(Plan::Pro);
    });

    test('rejects an address already in use', function (): void {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Copy',
                'email' => $admin->email,
                'password' => 'password-123',
                'password_confirmation' => 'password-123',
            ])
            ->assertSessionHasErrors(['email' => 'The email has already been taken.']);
    });

    test('refuses roles from staff who cannot hand them out', function (): void {
        $support = User::factory()->withPermissions([AdminPermission::AccessAdmin, AdminPermission::ManageUsers])->create();
        Role::findOrCreate('finance');

        $this->actingAs($support)
            ->post(route('admin.users.store'), [
                'name' => 'Sneaky',
                'email' => 'sneaky@example.com',
                'password' => 'password-123',
                'password_confirmation' => 'password-123',
                'roles' => ['finance'],
            ])
            ->assertSessionHasErrors('roles');

        expect(User::query()->where('email', 'sneaky@example.com')->exists())->toBeFalse();
    });

    test('opens an account holding the roles it was given', function (): void {
        $admin = User::factory()->admin()->create();
        Role::findOrCreate('finance');

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'New Staff',
                'email' => 'staff@example.com',
                'password' => 'password-123',
                'password_confirmation' => 'password-123',
                'roles' => ['finance'],
            ])
            ->assertSessionHasNoErrors();

        expect(User::query()->where('email', 'staff@example.com')->sole()->hasRole('finance'))->toBeTrue();
    });

    test('refuses to open an admin account for a role manager who is not an admin', function (): void {
        $manager = User::factory()->withPermissions([
            AdminPermission::AccessAdmin,
            AdminPermission::ManageUsers,
            AdminPermission::ManageRoles,
        ])->create();
        Role::findOrCreate(Role::ADMIN);

        $this->actingAs($manager)
            ->post(route('admin.users.store'), [
                'name' => 'Climber',
                'email' => 'climber@example.com',
                'password' => 'password-123',
                'password_confirmation' => 'password-123',
                'roles' => [Role::ADMIN],
            ])
            ->assertSessionHasErrors(['roles' => 'Only an admin can make somebody an admin.']);

        expect(User::query()->where('email', 'climber@example.com')->exists())->toBeFalse();
    });
});

describe('update', function (): void {
    test('a changed address loses its verification', function (): void {
        $admin = User::factory()->admin()->create();
        $pilot = User::factory()->create();

        $this->actingAs($admin)
            ->put(route('admin.users.update', $pilot), ['name' => $pilot->name, 'email' => 'moved@example.com'])
            ->assertRedirect();

        expect($pilot->fresh()?->email_verified_at)->toBeNull();
    });

    test('leaves roles alone when none were sent', function (): void {
        $admin = User::factory()->admin()->create();
        $editor = User::factory()->withPermissions([AdminPermission::AccessAdmin], 'content-manager')->create();

        $this->actingAs($admin)
            ->put(route('admin.users.update', $editor), ['name' => 'Renamed', 'email' => $editor->email])
            ->assertRedirect();

        expect($editor->fresh()?->hasRole('content-manager'))->toBeTrue();
    });

    test('an empty role list takes every role away', function (): void {
        $admin = User::factory()->admin()->create();
        $editor = User::factory()->withPermissions([AdminPermission::AccessAdmin], 'content-manager')->create();

        $this->actingAs($admin)
            ->put(route('admin.users.update', $editor), ['name' => $editor->name, 'email' => $editor->email, 'roles' => []])
            ->assertRedirect();

        expect($editor->fresh()?->roles)->toHaveCount(0);
    });
});

describe('destroy', function (): void {
    test('refuses an account Kelviq is still billing', function (string $status): void {
        $admin = User::factory()->admin()->create();
        $subscriber = User::factory()->create();
        fakeSubscriptions([['id' => 'sub_1', 'status' => $status, 'endDate' => null]]);

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $subscriber))
            ->assertRedirect()
            ->assertInertiaFlash('toast.message', 'This account still has a subscription Kelviq is billing. Cancel it in the Kelviq dashboard first, then delete the account.');

        $this->assertModelExists($subscriber);

        Http::assertSent(fn (Request $request): bool => $request['customer_id'] === $subscriber->uuid);
    })->with(['active', 'TRIALING', 'past_due']);

    test('allows an account whose subscriptions are winding down, over, or paid for once', function (): void {
        $admin = User::factory()->admin()->create();
        $subscriber = User::factory()->create();
        fakeSubscriptions([
            ['id' => 'sub_1', 'status' => 'active', 'endDate' => '2026-10-27'],
            ['id' => 'sub_2', 'status' => 'cancelled', 'endDate' => null],
            ['id' => 'sub_3', 'endDate' => null],
            ['id' => 'lifetime_1', 'status' => 'active', 'endDate' => null, 'billingType' => 'ONE_TIME'],
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $subscriber))
            ->assertRedirect(route('admin.users.index'));

        $this->assertModelMissing($subscriber);
    });

    test('allows an account kelviq has never heard of', function (): void {
        $admin = User::factory()->admin()->create();
        $pilot = User::factory()->create();
        fakeKelviq(responses: ['sandboxapi.kelviq.com/api/v1/subscriptions/*' => Http::response(['detail' => 'Not found.'], 404)]);

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $pilot))
            ->assertRedirect(route('admin.users.index'));

        $this->assertModelMissing($pilot);
    });

    test('keeps an account whose billing kelviq cannot answer for', function (mixed $response): void {
        $admin = User::factory()->admin()->create();
        $pilot = User::factory()->create();
        fakeKelviq(responses: ['sandboxapi.kelviq.com/api/v1/subscriptions/*' => $response]);

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $pilot))
            ->assertRedirect()
            ->assertInertiaFlash('toast.message', "Kelviq could not be reached to check this account's subscription, so nothing was deleted. Please try again.");

        $this->assertModelExists($pilot);
    })->with([
        'a server error' => fn () => Http::response('down', 503),
        'no connection' => fn () => Http::failedConnection(),
    ]);

    test('removes the photo files the rows pointed at', function (): void {
        Storage::fake('photos');

        $admin = User::factory()->admin()->create();
        $pilot = User::factory()->create();
        $photo = DronePhoto::factory()->for($pilot)->create();
        Storage::disk('photos')->put($photo->path, 'image');

        $this->actingAs($admin)->delete(route('admin.users.destroy', $pilot))->assertRedirect();

        Storage::disk('photos')->assertMissing($photo->path);
    });
});

test("marks an address verified on the pilot's behalf", function (): void {
    $admin = User::factory()->admin()->create();
    $pilot = User::factory()->unverified()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.verification.store', $pilot))
        ->assertRedirect();

    expect($pilot->fresh()?->hasVerifiedEmail())->toBeTrue();
});

test('reports an address that was already verified and changes nothing', function (): void {
    $admin = User::factory()->admin()->create();
    $pilot = User::factory()->create();
    $verifiedAt = $pilot->email_verified_at;

    $this->actingAs($admin)
        ->post(route('admin.users.verification.store', $pilot))
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'That address was already verified.']);

    expect($pilot->fresh()?->email_verified_at?->toJSON())->toBe($verifiedAt?->toJSON());
});

test("the account page lists the pilot's recent runs by mission and course", function (): void {
    $admin = User::factory()->admin()->create();
    $pilot = User::factory()->create();
    $course = Course::factory()->create(['title' => 'Night Ops']);
    $challenge = Challenge::factory()->for($course)->create(['title' => 'Dark Hover']);
    $run = ChallengeRun::factory()->for($pilot)->for($challenge)->create();

    $this->actingAs($admin)
        ->get(route('admin.users.show', $pilot))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('recentRuns', 1)
            ->where('recentRuns.0.uuid', $run->uuid)
            ->where('recentRuns.0.challengeTitle', 'Dark Hover')
            ->where('recentRuns.0.courseTitle', 'Night Ops'));
});

test('the account page shows the plan and nothing about money', function (): void {
    $support = User::factory()->withPermissions([AdminPermission::AccessAdmin, AdminPermission::ManageUsers])->create();
    $pilot = User::factory()->create();

    $this->actingAs($support)
        ->get(route('admin.users.show', $pilot))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('admin/users/show')
            ->where('account.uuid', $pilot->uuid)
            ->where('account.plan.value', 'starter')
            ->missing('subscriptions')
            ->missing('orders'));
});

/**
 * @param  array<int, array<string, mixed>>  $subscriptions
 */
function fakeSubscriptions(array $subscriptions): void
{
    fakeKelviq(responses: ['sandboxapi.kelviq.com/api/v1/subscriptions/*' => Http::response([
        'count' => count($subscriptions),
        'results' => $subscriptions,
    ])]);
}
