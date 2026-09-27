<?php

declare(strict_types=1);

use App\Enums\AdminPermission;
use App\Models\Challenge;
use App\Models\ChallengeRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

test('the overview counts the last day of flying and the last week of missions', function (): void {
    $admin = User::factory()->admin()->create();
    $challenge = Challenge::factory()->create();

    ChallengeRun::factory()->for($challenge)->completed()->create();
    ChallengeRun::factory()->for($challenge)->create();
    ChallengeRun::factory()->for($challenge)->create(['created_at' => now()->subDays(2)]);
    ChallengeRun::factory()->for($challenge)->create(['created_at' => now()->subDays(8)]);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('admin/dashboard')
            ->missing('overview')
            ->loadDeferredProps(fn (AssertableInertia $reload): AssertableInertia => $reload
                ->where('overview.flying.runs', 2)
                ->where('overview.flying.clearRate', 0.5)
                ->where('overview.busiestMissions.0.challengeSlug', $challenge->slug)
                ->where('overview.busiestMissions.0.runs', 3)));
});

test('the overview counts each account seen in the last five minutes once', function (): void {
    config(['session.driver' => 'database']);
    $admin = User::factory()->admin()->create();
    $pilot = User::factory()->create();
    $idle = User::factory()->create();
    DB::table('sessions')->insert([
        ['id' => 'a', 'user_id' => $pilot->id, 'payload' => '', 'last_activity' => now()->subMinute()->getTimestamp()],
        ['id' => 'b', 'user_id' => $pilot->id, 'payload' => '', 'last_activity' => now()->subMinutes(2)->getTimestamp()],
        ['id' => 'c', 'user_id' => null, 'payload' => '', 'last_activity' => now()->getTimestamp()],
        ['id' => 'd', 'user_id' => $idle->id, 'payload' => '', 'last_activity' => now()->subMinutes(6)->getTimestamp()],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            // The pilot, once for two sessions, and the admin reading the page.
            ->loadDeferredProps(fn (AssertableInertia $reload): AssertableInertia => $reload
                ->where('overview.online', 2)));
});

test('the overview does not claim a count of pilots online it cannot read', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->loadDeferredProps(fn (AssertableInertia $reload): AssertableInertia => $reload
                ->where('overview.online', null)));
});

test('the daily chart fills quiet days with zero', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->loadDeferredProps(fn (AssertableInertia $reload): AssertableInertia => $reload
                ->has('overview.daily', 14)
                ->where('overview.daily.0.runs', 0)
                ->where('overview.daily.13.date', now()->toDateString())));
});

test('the activity feed reads accounts, runs and quizzes, and nothing about money', function (): void {
    $support = User::factory()->withPermissions([AdminPermission::AccessAdmin])->create();

    $this->actingAs($support)
        ->get(route('admin.activity'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('admin/activity')
            ->where('kinds', ['signup', 'run', 'quiz']));
});

test('a filter the feed does not know falls back to everything', function (): void {
    $support = User::factory()->withPermissions([AdminPermission::AccessAdmin])->create();

    $this->actingAs($support)
        ->get(route('admin.activity', ['type' => 'order']))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('type', null));
});

test('the activity feed pages across sources newest first', function (): void {
    $admin = User::factory()->admin()->create(['created_at' => now()->subYear()]);
    $challenge = Challenge::factory()->create();
    ChallengeRun::factory()->for($challenge)->count(30)->create(['created_at' => now()->subMinutes(10)]);
    User::factory()->create(['created_at' => now()]);

    $this->actingAs($admin)
        ->get(route('admin.activity'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('activity.items', 25)
            ->where('activity.items.0.kind', 'signup')
            ->where('activity.hasMore', true));

    $this->actingAs($admin)
        ->get(route('admin.activity', ['page' => 2]))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('activity.page', 2)
            ->where('activity.hasMore', true));
});
