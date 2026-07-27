<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WelcomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_can_visit_the_landing_page(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
    }

    public function test_landing_page_advertises_the_published_catalog_in_order(): void
    {
        $second = Course::factory()->create(['title' => 'Precision Flight', 'order' => 1]);
        $first = Course::factory()->create(['title' => 'Drone Basics', 'order' => 0]);

        Challenge::factory()->count(2)->for($first)->create();
        Challenge::factory()->for($second)->create();

        $response = $this->get(route('home'));

        $response->assertInertia(fn ($page) => $page
            ->component('welcome')
            ->has('courses', 2)
            ->where('courses.0.title', 'Drone Basics')
            ->where('courses.0.challengesCount', 2)
            ->where('courses.1.title', 'Precision Flight')
            ->where('courses.1.challengesCount', 1)
            ->where('missionCount', 3));
    }

    public function test_landing_page_hides_unpublished_courses_and_challenges(): void
    {
        $published = Course::factory()->create();
        Challenge::factory()->for($published)->create();
        Challenge::factory()->for($published)->unpublished()->create();

        $hidden = Course::factory()->unpublished()->create();
        Challenge::factory()->for($hidden)->create();

        $response = $this->get(route('home'));

        $response->assertInertia(fn ($page) => $page
            ->has('courses', 1)
            ->where('courses.0.challengesCount', 1)
            ->where('missionCount', 1));
    }

    public function test_landing_page_renders_with_an_empty_catalog(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('courses', 0)
            ->where('missionCount', 0));
    }
}
