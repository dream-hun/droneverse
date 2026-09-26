<?php

declare(strict_types=1);

use App\Enums\AdminPermission;
use App\Enums\Plan;
use App\Enums\SubscriptionStatus;
use App\Models\DronePhoto;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
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
                'plan_override' => 'team',
            ])
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'success');

        $user = User::query()->where('email', 'new@example.com')->sole();

        expect($user->email_verified_at)->toBeNull()
            ->and($user->plan())->toBe(Plan::Team);
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
    test('refuses an account Creem is still billing', function (): void {
        $admin = User::factory()->admin()->create();
        $subscriber = User::factory()->create();
        Subscription::factory()->billable($subscriber)->create();

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $subscriber))
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'error');

        $this->assertModelExists($subscriber);
    });

    test('allows an account whose subscription is winding down', function (): void {
        $admin = User::factory()->admin()->create();
        $subscriber = User::factory()->create();
        Subscription::factory()->billable($subscriber)->scheduledCancel()->create();

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $subscriber))
            ->assertRedirect(route('admin.users.index'));

        $this->assertModelMissing($subscriber);
    });

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

test('the account page leaves billing out for staff without view_finance', function (): void {
    $support = User::factory()->withPermissions([AdminPermission::AccessAdmin, AdminPermission::ManageUsers])->create();
    $pilot = User::factory()->create();

    $this->actingAs($support)
        ->get(route('admin.users.show', $pilot))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('admin/users/show')
            ->where('account.uuid', $pilot->uuid)
            ->where('subscriptions', null)
            ->where('orders', null));
});

describe('cancelling a subscription', function (): void {
    test('schedules the end at Creem, after which the account can be deleted', function (): void {
        config(['creem.api_key' => 'creem_test_key']);

        $admin = User::factory()->admin()->create();
        $subscriber = User::factory()->create();
        $subscription = Subscription::factory()->billable($subscriber)->create();

        Http::fake(['*/subscriptions/'.$subscription->creem_id.'/cancel' => Http::response([
            'id' => $subscription->creem_id,
            'object' => 'subscription',
            'product' => $subscription->product_id,
            'customer' => $subscription->customer_id,
            'status' => SubscriptionStatus::ScheduledCancel->value,
        ])]);

        $this->actingAs($admin)
            ->get(route('admin.users.show', $subscriber))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('canCancelSubscription', true));

        $this->actingAs($admin)
            ->delete(route('admin.users.subscription.destroy', $subscriber))
            ->assertInertiaFlash('toast.type', 'success');

        expect($subscription->refresh()->cancelled())->toBeTrue();

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $subscriber))
            ->assertRedirect(route('admin.users.index'));

        $this->assertModelMissing($subscriber);
    });

    test('reports a Creem failure and leaves the subscription billing', function (): void {
        config(['creem.api_key' => 'creem_test_key']);
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);

        $admin = User::factory()->admin()->create();
        $subscriber = User::factory()->create();
        $subscription = Subscription::factory()->billable($subscriber)->create();

        $this->actingAs($admin)
            ->delete(route('admin.users.subscription.destroy', $subscriber))
            ->assertInertiaFlash('toast.message', 'Creem could not cancel the subscription. Please try again.');

        expect($subscription->refresh()->cancelled())->toBeFalse();
    });

    test('is not offered for a subscription already winding down', function (): void {
        $admin = User::factory()->admin()->create();
        $subscriber = User::factory()->create();
        Subscription::factory()->billable($subscriber)->scheduledCancel()->create();

        $this->actingAs($admin)
            ->get(route('admin.users.show', $subscriber))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('canCancelSubscription', false));

        $this->actingAs($admin)
            ->delete(route('admin.users.subscription.destroy', $subscriber))
            ->assertInertiaFlash('toast.type', 'error');
    });

    test('needs view_finance as well as manage_users', function (): void {
        $support = User::factory()->withPermissions([AdminPermission::AccessAdmin, AdminPermission::ManageUsers])->create();
        $subscriber = User::factory()->create();
        Subscription::factory()->billable($subscriber)->create();

        $this->actingAs($support)
            ->delete(route('admin.users.subscription.destroy', $subscriber))
            ->assertForbidden();
    });
});
