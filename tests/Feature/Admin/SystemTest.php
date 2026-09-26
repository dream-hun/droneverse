<?php

declare(strict_types=1);

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function failJob(): string
{
    $uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['uuid' => $uuid, 'displayName' => 'App\\Jobs\\SendReceipt', 'attempts' => 3, 'data' => []]),
        'exception' => "RuntimeException: Mail server refused\n#0 stack",
        'failed_at' => now(),
    ]);

    return $uuid;
}

test('staff without view_system cannot open it', function (): void {
    $support = User::factory()->withPermissions([AdminPermission::AccessAdmin, AdminPermission::ManageUsers])->create();

    $this->actingAs($support)->get(route('admin.system'))->assertForbidden();
});

test('reports live checks and the failed jobs', function (): void {
    $admin = User::factory()->admin()->create();
    failJob();

    $this->actingAs($admin)
        ->get(route('admin.system'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('admin/system')
            ->where('report.checks.0.name', 'Database')
            ->where('report.checks.0.ok', true)
            ->where('report.checks.1.ok', true)
            ->where('report.checks.2.name', 'Queue')
            ->where('report.checks.2.ok', false)
            ->where('report.failedJobs.0.job', 'App\\Jobs\\SendReceipt')
            ->where('report.failedJobs.0.exception', 'RuntimeException: Mail server refused')
            ->where('logViewerUrl', null));
});

test('links the log viewer only for people its own allowlist admits', function (): void {
    $admin = User::factory()->admin()->create(['email' => 'ops@droneverse.test']);
    config()->set('logging.viewer_emails', ['ops@droneverse.test']);

    $this->actingAs($admin)
        ->get(route('admin.system'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('logViewerUrl', route('log-viewer.index')));
});

test('a retried job goes back on its queue and leaves the failed list', function (): void {
    $admin = User::factory()->admin()->create();
    $uuid = failJob();

    $this->actingAs($admin)
        ->post(route('admin.system.failed-jobs.retry', $uuid))
        ->assertInertiaFlash('toast.type', 'success');

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(1);
});

test('a discarded job is gone without running', function (): void {
    $admin = User::factory()->admin()->create();
    $uuid = failJob();

    $this->actingAs($admin)
        ->delete(route('admin.system.failed-jobs.destroy', $uuid))
        ->assertInertiaFlash('toast.type', 'success');

    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('retrying a job that is no longer there says so', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.system.failed-jobs.retry', (string) Str::uuid()))
        ->assertInertiaFlash('toast.type', 'error');
});
