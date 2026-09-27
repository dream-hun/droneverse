<?php

declare(strict_types=1);

use App\Enums\AdminPermission;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Mockery\MockInterface;

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

test('counts the watched tables in one query', function (): void {
    $admin = User::factory()->admin()->create();
    User::factory()->count(2)->create();
    failJob();

    DB::enableQueryLog();

    $this->actingAs($admin)
        ->get(route('admin.system'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('report.tables.0.name', 'users')
            ->where('report.tables.0.rows', 3)
            ->where('report.tables.9.name', 'failed_jobs')
            ->where('report.tables.9.rows', 1));

    // Quoted by the connection's own grammar: MySQL's CI job runs this too.
    $grammar = DB::getQueryGrammar();
    $counted = fn (string $table): string => sprintf('(select count(*) from %s)', $grammar->wrapTable($table));

    $statements = array_filter(
        array_column(DB::getQueryLog(), 'query'),
        fn (string $query): bool => str_contains($query, $counted('users')) && str_contains($query, $counted('failed_jobs')),
    );

    expect($statements)->toHaveCount(1);
});

test('a figure that cannot be read shows as unknown and reports why', function (): void {
    Exceptions::fake();
    config([
        'queue.default' => 'database',
        'queue.connections.database.table' => 'missing_jobs',
    ]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.system'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('report.queue.pending', null)
            ->where('report.queue.oldestPendingSeconds', null));

    Exceptions::assertReported(QueryException::class);
});

/**
 * Throw from inside every statement whose SQL contains all of `$fragments`,
 * the way a lost connection or a missing grant would. Fragments rather than a
 * whole statement, so the match holds under every driver's quoting.
 */
function failQueriesContaining(string ...$fragments): void
{
    DB::listen(function (QueryExecuted $query) use ($fragments): void {
        $matches = array_all($fragments, fn (string $fragment): bool => str_contains($query->sql, $fragment));

        throw_if($matches, RuntimeException::class, 'the database went away');
    });
}

/**
 * The live schema builder, with only the methods a test names replaced.
 */
function schemaWith(): MockInterface
{
    $schema = Mockery::mock(Schema::getFacadeRoot());
    Schema::swap($schema);

    return $schema;
}

test('a database queue reports how long its oldest job has waited', function (): void {
    $this->freezeSecond();
    config(['queue.default' => 'database']);
    $admin = User::factory()->admin()->create();
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{}',
        'attempts' => 0,
        'available_at' => now()->subSeconds(90)->getTimestamp(),
        'created_at' => now()->subSeconds(90)->getTimestamp(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.system'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('report.queue.pending', 1)
            ->where('report.queue.oldestPendingSeconds', 90));
});

test('a failed jobs table that cannot be read fails the queue check and reports why', function (): void {
    Exceptions::fake();
    config(['queue.failed.table' => 'missing_failed_jobs']);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.system'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('report.checks.2.name', 'Queue')
            ->where('report.checks.2.ok', false)
            ->where('report.queue.failed', null)
            ->where('report.failedJobs', []));

    Exceptions::assertReported(QueryException::class);
});

test('failures kept outside the database pass the queue check and list nothing', function (): void {
    config(['queue.failed.driver' => 'null']);
    $admin = User::factory()->admin()->create();
    failJob();

    $this->actingAs($admin)
        ->get(route('admin.system'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('report.checks.2.ok', true)
            ->where('report.checks.2.detail', 'Failures are not stored in the database')
            ->where('report.failedJobs', []));
});

test('a cache that accepts writes and returns nothing fails its check', function (): void {
    $this->mock(Repository::class, function (MockInterface $cache): void {
        $cache->shouldReceive('put', 'forget');
        $cache->shouldReceive('get')->andReturnNull();
    });
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.system'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('report.checks.1.name', 'Cache')
            ->where('report.checks.1.ok', false)
            ->where('report.checks.1.detail', 'A value written to the cache did not come back.'));
});

test('table sizes are read from the schema, skipping entries it cannot name', function (): void {
    schemaWith()->shouldReceive('getTables')->andReturn([
        'not a table',
        ['name' => null],
        ['name' => 'users', 'size' => 4096],
    ]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.system'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('report.tables.0.name', 'users')
            ->where('report.tables.0.size', 4096)
            ->where('report.tables.1.size', null));
});

test('table sizes that cannot be read show as unknown and report why', function (): void {
    Exceptions::fake();
    schemaWith()->shouldReceive('getTables')->andThrow(new RuntimeException('no catalogue access'));
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.system'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('report.tables.0.name', 'users')
            ->where('report.tables.0.size', null));

    Exceptions::assertReported(RuntimeException::class);
});

test('a database with none of the watched tables lists none', function (): void {
    schemaWith()->shouldReceive('hasTable')->andReturnFalse();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.system'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('report.tables', []));
});

test('row counts that cannot be read leave every count unknown and report why', function (): void {
    Exceptions::fake();
    $admin = User::factory()->admin()->create();
    failQueriesContaining('select count(*)');

    $this->actingAs($admin)
        ->get(route('admin.system'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('report.tables.0.name', 'users')
            ->where('report.tables.0.rows', null));

    Exceptions::assertReported(RuntimeException::class);
});

test('sessions stored in the database are counted, guests included', function (): void {
    config(['session.driver' => 'database']);
    $admin = User::factory()->admin()->create();
    DB::table('sessions')->insert([
        ['id' => 'guest', 'user_id' => null, 'payload' => '', 'last_activity' => now()->subMinute()->getTimestamp()],
        ['id' => 'stale', 'user_id' => null, 'payload' => '', 'last_activity' => now()->subHours(2)->getTimestamp()],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.system'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('report.sessions.last5Minutes', 1)
            ->where('report.sessions.last60Minutes', 1));
});

test('sessions that cannot be counted show as unknown and report why', function (): void {
    Exceptions::fake();
    config(['session.driver' => 'database']);
    $admin = User::factory()->admin()->create();
    failQueriesContaining('last_activity', '>=');

    $this->actingAs($admin)
        ->get(route('admin.system'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('report.sessions.last5Minutes', null));

    Exceptions::assertReported(RuntimeException::class);
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
