<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\DronePhoto;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** A real 1x1 PNG so image validation exercises actual decoding. */
const TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

test('a photo cannot be uploaded to a mission the plan does not cover', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();
    $course = Course::factory()->requiring(Plan::Pro)->create();
    $challenge = Challenge::factory()->for($course)->create();

    $response = $this->actingAs($user)->postJson(
        route('challenges.photos.store', [$course, $challenge]),
        payload(),
    );

    $response->assertForbidden();

    expect(DronePhoto::query()->count())->toBe(0);
    Storage::disk('photos')->assertDirectoryEmpty('/');
});

test('guests cannot store photos', function (): void {
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $response = $this->post(
        route('challenges.photos.store', [$course, $challenge]),
        payload(),
    );

    $response->assertRedirect(route('login'));
});

test('a simulator photo is stored on disk and in the log', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $response = $this->actingAs($user)->postJson(
        route('challenges.photos.store', [$course, $challenge]),
        payload(['label' => 'rooftop-drop']),
    );

    $response->assertCreated();
    $response->assertJsonPath('label', 'rooftop-drop');

    $photo = DronePhoto::query()->sole();

    expect($photo->user_id)->toBe($user->id);
    expect($photo->challenge_id)->toBe($challenge->id);
    expect($photo->label)->toBe('rooftop-drop');
    expect($photo->position)->toBe(['x' => 4.2, 'y' => 9.5, 'z' => -8.1, 'headingDeg' => 182.5]);
    Storage::disk('photos')->assertExists($photo->path);
    expect($photo->path)->toEndWith('.png');
});

test('a photo without telemetry stores a null position', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $response = $this->actingAs($user)->postJson(
        route('challenges.photos.store', [$course, $challenge]),
        ['image' => 'data:image/png;base64,'.TINY_PNG],
    );

    $response->assertCreated();
    expect(DronePhoto::query()->sole()->position)->toBeNull();
});

test('payloads that are not data urls are rejected', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $response = $this->actingAs($user)->postJson(
        route('challenges.photos.store', [$course, $challenge]),
        payload(['image' => 'https://example.com/image.png']),
    );

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('image');
    $this->assertDatabaseCount('drone_photos', 0);
});

test('base64 that is not a real image is rejected', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $response = $this->actingAs($user)->postJson(
        route('challenges.photos.store', [$course, $challenge]),
        payload([
            'image' => 'data:image/png;base64,'.base64_encode('definitely not an image'),
        ]),
    );

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('image');
    $this->assertDatabaseCount('drone_photos', 0);
    expect(Storage::disk('photos')->allFiles())->toBeEmpty();
});

test('photos cannot be stored on unpublished content', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->unpublished()->create();

    $response = $this->actingAs($user)->postJson(
        route('challenges.photos.store', [$course, $challenge]),
        payload(),
    );

    $response->assertNotFound();
    $this->assertDatabaseCount('drone_photos', 0);
});

test('photos cannot be stored for a challenge from another course', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();
    $course = Course::factory()->create();
    $otherCourse = Course::factory()->create();
    $challenge = Challenge::factory()->for($otherCourse)->create();

    $response = $this->actingAs($user)->postJson(
        route('challenges.photos.store', [$course, $challenge]),
        payload(),
    );

    $response->assertNotFound();
    $this->assertDatabaseCount('drone_photos', 0);
});

test('the photo log shows only the users own photos', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $mine = DronePhoto::factory()->for($user)->for($challenge)->create(['label' => 'mine']);
    DronePhoto::factory()->for($other)->for($challenge)->create(['label' => 'theirs']);

    $response = $this->actingAs($user)->get(route('photos.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('photos/index')
        ->count('photos', 1)
        ->where('photos.0.id', $mine->uuid)
        ->where('photos.0.label', 'mine')
        ->where('photos.0.challengeTitle', $challenge->title)
        ->where('total', 1));
});

test('a stored photo is identified to the client by its uuid', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $response = $this->actingAs($user)->postJson(
        route('challenges.photos.store', [$course, $challenge]),
        payload(),
    );

    $response->assertCreated();

    $photo = DronePhoto::query()->sole();

    expect(Str::isUuid($photo->uuid))->toBeTrue();
    $response->assertJsonPath('id', $photo->uuid);

    // The auto-increment key must not travel with it: the whole point of
    // the uuid is that the client never learns the row's position.
    expect($response->json('id'))->not->toBe($photo->id);
});

test('a photo is addressed by uuid and not by id', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();
    $photo = DronePhoto::factory()->for($user)->create();
    Storage::disk('photos')->put($photo->path, 'jpeg-bytes');

    expect(route('photos.destroy', $photo))->toContain($photo->uuid);

    // A malformed key is rejected as content that does not exist, which
    // is what stops the endpoint from confirming anything about the ids
    // either side of it.
    $this->actingAs($user)
        ->delete(url('/photos/'.$photo->id))
        ->assertNotFound();

    $this->assertDatabaseHas('drone_photos', ['id' => $photo->id]);
    Storage::disk('photos')->assertExists($photo->path);
});

test('guests cannot view the photo log', function (): void {
    $response = $this->get(route('photos.index'));

    $response->assertRedirect(route('login'));
});

test('a user can delete their own photo and its file', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();
    $photo = DronePhoto::factory()->for($user)->create();
    Storage::disk('photos')->put($photo->path, 'jpeg-bytes');

    $response = $this->actingAs($user)->delete(route('photos.destroy', $photo));

    $response->assertRedirect();
    $this->assertDatabaseMissing('drone_photos', ['id' => $photo->id]);
    Storage::disk('photos')->assertMissing($photo->path);
});

test('a user cannot delete someone elses photo', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();
    $other = User::factory()->create();
    $photo = DronePhoto::factory()->for($other)->create();
    Storage::disk('photos')->put($photo->path, 'jpeg-bytes');

    $response = $this->actingAs($user)->delete(route('photos.destroy', $photo));

    $response->assertForbidden();
    $this->assertDatabaseHas('drone_photos', ['id' => $photo->id]);
    Storage::disk('photos')->assertExists($photo->path);
});

test('the photo log has a storage cap', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    DronePhoto::factory()->for($user)->for($challenge)->count(500)->create();

    $response = $this->actingAs($user)->postJson(
        route('challenges.photos.store', [$course, $challenge]),
        payload(),
    );

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('image');
    $this->assertDatabaseCount('drone_photos', 500);

    expect(Storage::disk('photos')->allFiles())->toBe([], 'a photo the quota refused was still written to the disk');
});

test('the quota is counted in the same transaction that writes the photo', function (): void {
    /*
     * A count followed by an insert is a check-then-act, and the
     * simulator uploads from a queue that can have several photos in
     * flight at once — so a pilot sitting on the limit could put as many
     * past it as they had requests in the air. What closes that is a lock
     * on the pilot's own row, held from the count until the insert
     * commits.
     *
     * The lock itself cannot be asserted here: this suite runs on SQLite,
     * which has no row locking and compiles `lockForUpdate` away to
     * nothing, and one connection cannot stage the race anyway. What can
     * be asserted is the property the lock depends on and that a refactor
     * would break first — that the two statements are inside one
     * transaction at all. A lock taken in a transaction the insert is not
     * part of is released before the insert happens and protects nothing.
     */
    Storage::fake('photos');

    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $levelAtInsert = null;

    Event::listen(
        'eloquent.creating: '.DronePhoto::class,
        function () use (&$levelAtInsert): void {
            $levelAtInsert = DB::transactionLevel();
        },
    );

    $this->actingAs($user)->postJson(
        route('challenges.photos.store', [$course, $challenge]),
        payload(),
    )->assertCreated();

    expect($levelAtInsert)->not->toBeNull('the photo was never inserted');
    expect($levelAtInsert)->toBeGreaterThan(0, 'the photo was written outside the transaction the quota was counted in');
});

test('a photo whose row never lands leaves no file behind', function (): void {
    /*
     * The file is the one write the transaction cannot roll back. If it
     * were left where it fell, every failed upload would cost bytes that
     * nothing knows about and nothing will ever come back for — invisible
     * to the pilot, invisible to the log, and paid for monthly.
     */
    Storage::fake('photos');

    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    Event::listen('eloquent.creating: '.DronePhoto::class, function (): never {
        throw new RuntimeException('the database went away');
    });

    try {
        $this->actingAs($user)->postJson(
            route('challenges.photos.store', [$course, $challenge]),
            payload(),
        );
    } catch (RuntimeException) {
        // The failure is the point; what it left behind is the assertion.
    }

    $this->assertDatabaseCount('drone_photos', 0);
    expect(Storage::disk('photos')->allFiles())
        ->toBe([], 'a failed upload left its bytes on the disk with no row pointing at them');
});

test('a photo url is a link that expires', function (): void {
    Storage::fake('photos');
    $this->freezeTime();

    $user = User::factory()->create();
    $photo = DronePhoto::factory()->for($user)->create();
    Storage::disk('photos')->put($photo->path, 'jpeg-bytes');

    parse_str((string) parse_url($photo->url(), PHP_URL_QUERY), $query);

    // A photo is private to the pilot who took it, so the URL has to stop
    // working on its own. A permanent link would outlive both the pilot's
    // access to the mission and the photo's own deletion.
    expect($query)->toHaveKey('expiration');
    expect((int) $query['expiration'])->toBe(now()->addMinutes(30)->getTimestamp());
});

test('the photo log serves expiring urls', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();
    DronePhoto::factory()->for($user)->for($challenge)->create();

    $response = $this->actingAs($user)->get(route('photos.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('photos/index')
        ->where('photos.0.url', fn (string $url): bool => str_contains($url, 'expiration=')));
});

test('a stored photo is returned with an expiring url', function (): void {
    Storage::fake('photos');

    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $response = $this->actingAs($user)->postJson(
        route('challenges.photos.store', [$course, $challenge]),
        payload(),
    );

    $response->assertCreated();
    expect((string) $response->json('url'))->toContain('expiration=');
});

test('photos are written to the configured disk', function (): void {
    Storage::fake('photos');
    Storage::fake('s3');
    config(['filesystems.photo_disk' => 's3']);

    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $response = $this->actingAs($user)->postJson(
        route('challenges.photos.store', [$course, $challenge]),
        payload(),
    );

    $response->assertCreated();

    $photo = DronePhoto::query()->sole();

    // The point of the config key: on a host whose filesystem does not
    // survive a release, nothing may fall back to the local disk.
    Storage::disk('s3')->assertExists($photo->path);
    Storage::disk('photos')->assertDirectoryEmpty('/');
});

test('deleting a photo removes the file from the configured disk', function (): void {
    Storage::fake('photos');
    Storage::fake('s3');
    config(['filesystems.photo_disk' => 's3']);

    $user = User::factory()->create();
    $photo = DronePhoto::factory()->for($user)->create();
    Storage::disk('s3')->put($photo->path, 'jpeg-bytes');

    $response = $this->actingAs($user)->delete(route('photos.destroy', $photo));

    $response->assertRedirect();
    $this->assertDatabaseMissing('drone_photos', ['id' => $photo->id]);
    Storage::disk('s3')->assertMissing($photo->path);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function payload(array $overrides = []): array
{
    return array_merge([
        'image' => 'data:image/png;base64,'.TINY_PNG,
        'label' => 'test-shot',
        'x' => 4.2,
        'y' => 9.5,
        'z' => -8.1,
        'heading' => 182.5,
    ], $overrides);
}
