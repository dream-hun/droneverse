<?php

declare(strict_types=1);

use App\Models\Challenge;
use App\Models\Course;
use Inertia\Testing\AssertableInertia;

test('guests can visit the landing page', function (): void {
    $response = $this->get(route('home'));

    $response->assertOk();
});

test('landing page advertises the published catalog in order', function (): void {
    $second = Course::factory()->create(['title' => 'Precision Flight', 'order' => 1]);
    $first = Course::factory()->create(['title' => 'Drone Basics', 'order' => 0]);

    Challenge::factory()->count(2)->for($first)->create();
    Challenge::factory()->for($second)->create();

    $response = $this->get(route('home'));

    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->component('welcome')
        ->has('courses', 2)
        ->where('courses.0.title', 'Drone Basics')
        ->where('courses.0.challengesCount', 2)
        ->where('courses.1.title', 'Precision Flight')
        ->where('courses.1.challengesCount', 1)
        ->where('missionCount', 3));
});

test('landing page hides unpublished courses and challenges', function (): void {
    $published = Course::factory()->create();
    Challenge::factory()->for($published)->create();
    Challenge::factory()->for($published)->unpublished()->create();

    $hidden = Course::factory()->unpublished()->create();
    Challenge::factory()->for($hidden)->create();

    $response = $this->get(route('home'));

    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->has('courses', 1)
        ->where('courses.0.challengesCount', 1)
        ->where('missionCount', 1));
});

test('landing page renders with an empty catalog', function (): void {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->has('courses', 0)
        ->where('missionCount', 0));
});
