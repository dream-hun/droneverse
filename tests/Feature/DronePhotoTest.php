<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\Course;
use App\Models\DronePhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DronePhotoTest extends TestCase
{
    use RefreshDatabase;

    /** A real 1x1 PNG so image validation exercises actual decoding. */
    private const string TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function test_guests_cannot_store_photos(): void
    {
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        $response = $this->post(
            route('challenges.photos.store', [$course, $challenge]),
            $this->payload(),
        );

        $response->assertRedirect(route('login'));
    }

    public function test_a_simulator_photo_is_stored_on_disk_and_in_the_log(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        $response = $this->actingAs($user)->postJson(
            route('challenges.photos.store', [$course, $challenge]),
            $this->payload(['label' => 'rooftop-drop']),
        );

        $response->assertCreated();
        $response->assertJsonPath('label', 'rooftop-drop');

        $photo = DronePhoto::query()->sole();

        $this->assertSame($user->id, $photo->user_id);
        $this->assertSame($challenge->id, $photo->challenge_id);
        $this->assertSame('rooftop-drop', $photo->label);
        $this->assertSame(
            ['x' => 4.2, 'y' => 9.5, 'z' => -8.1, 'headingDeg' => 182.5],
            $photo->position,
        );
        Storage::disk('public')->assertExists($photo->path);
        $this->assertStringEndsWith('.png', $photo->path);
    }

    public function test_a_photo_without_telemetry_stores_a_null_position(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        $response = $this->actingAs($user)->postJson(
            route('challenges.photos.store', [$course, $challenge]),
            ['image' => 'data:image/png;base64,'.self::TINY_PNG],
        );

        $response->assertCreated();
        $this->assertNull(DronePhoto::query()->sole()->position);
    }

    public function test_payloads_that_are_not_data_urls_are_rejected(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        $response = $this->actingAs($user)->postJson(
            route('challenges.photos.store', [$course, $challenge]),
            $this->payload(['image' => 'https://example.com/image.png']),
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('image');
        $this->assertDatabaseCount('drone_photos', 0);
    }

    public function test_base64_that_is_not_a_real_image_is_rejected(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        $response = $this->actingAs($user)->postJson(
            route('challenges.photos.store', [$course, $challenge]),
            $this->payload([
                'image' => 'data:image/png;base64,'.base64_encode('definitely not an image'),
            ]),
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('image');
        $this->assertDatabaseCount('drone_photos', 0);
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_photos_cannot_be_stored_on_unpublished_content(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->unpublished()->create();

        $response = $this->actingAs($user)->postJson(
            route('challenges.photos.store', [$course, $challenge]),
            $this->payload(),
        );

        $response->assertNotFound();
        $this->assertDatabaseCount('drone_photos', 0);
    }

    public function test_photos_cannot_be_stored_for_a_challenge_from_another_course(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $course = Course::factory()->create();
        $otherCourse = Course::factory()->create();
        $challenge = Challenge::factory()->for($otherCourse)->create();

        $response = $this->actingAs($user)->postJson(
            route('challenges.photos.store', [$course, $challenge]),
            $this->payload(),
        );

        $response->assertNotFound();
        $this->assertDatabaseCount('drone_photos', 0);
    }

    public function test_the_photo_log_shows_only_the_users_own_photos(): void
    {
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
            ->where('photos.0.id', $mine->id)
            ->where('photos.0.label', 'mine')
            ->where('photos.0.challengeTitle', $challenge->title)
            ->where('total', 1));
    }

    public function test_guests_cannot_view_the_photo_log(): void
    {
        $response = $this->get(route('photos.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_a_user_can_delete_their_own_photo_and_its_file(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $photo = DronePhoto::factory()->for($user)->create();
        Storage::disk('public')->put($photo->path, 'jpeg-bytes');

        $response = $this->actingAs($user)->delete(route('photos.destroy', $photo));

        $response->assertRedirect();
        $this->assertDatabaseMissing('drone_photos', ['id' => $photo->id]);
        Storage::disk('public')->assertMissing($photo->path);
    }

    public function test_a_user_cannot_delete_someone_elses_photo(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $other = User::factory()->create();
        $photo = DronePhoto::factory()->for($other)->create();
        Storage::disk('public')->put($photo->path, 'jpeg-bytes');

        $response = $this->actingAs($user)->delete(route('photos.destroy', $photo));

        $response->assertForbidden();
        $this->assertDatabaseHas('drone_photos', ['id' => $photo->id]);
        Storage::disk('public')->assertExists($photo->path);
    }

    public function test_the_photo_log_has_a_storage_cap(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $course = Course::factory()->create();
        $challenge = Challenge::factory()->for($course)->create();

        DronePhoto::factory()->for($user)->for($challenge)->count(500)->create();

        $response = $this->actingAs($user)->postJson(
            route('challenges.photos.store', [$course, $challenge]),
            $this->payload(),
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('image');
        $this->assertDatabaseCount('drone_photos', 500);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'image' => 'data:image/png;base64,'.self::TINY_PNG,
            'label' => 'test-shot',
            'x' => 4.2,
            'y' => 9.5,
            'z' => -8.1,
            'heading' => 182.5,
        ], $overrides);
    }
}
