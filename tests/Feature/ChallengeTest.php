<?php

declare(strict_types=1);

use App\Enums\ChallengeStatus;
use App\Enums\Plan;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\User;
use App\Models\UserChallengeProgress;

dataset('malformed paths', [
    'a sample missing an axis' => [[
        ['t' => 0, 'x' => 0, 'y' => 0.15, 'z' => 0],
        ['t' => 1, 'x' => 0, 'z' => 0],
    ]],
    'a coordinate that is not a number' => [[
        ['t' => 0, 'x' => 0, 'y' => 0.15, 'z' => 0],
        ['t' => 1, 'x' => 'over there', 'y' => 1.5, 'z' => 0],
    ]],
    'a coordinate off any map' => [[
        ['t' => 0, 'x' => 0, 'y' => 0.15, 'z' => 0],
        ['t' => 1, 'x' => 0, 'y' => 1.5, 'z' => 250000],
    ]],
    'a timestamp before the run began' => [[
        ['t' => -1, 'x' => 0, 'y' => 0.15, 'z' => 0],
        ['t' => 0, 'x' => 0, 'y' => 1.5, 'z' => 0],
    ]],
    'a sample that is not a sample' => [[
        ['t' => 0, 'x' => 0, 'y' => 0.15, 'z' => 0],
        ['nope'],
    ]],
]);

test('guests are redirected to the login page', function (): void {
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $response = $this->get(route('challenges.show', [$course, $challenge]));

    $response->assertRedirect(route('login'));
});

test('authenticated users can view the simulator', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $response = $this->actingAs($user)->get(route('challenges.show', [$course, $challenge]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('challenges/play')
        ->where('challenge.slug', $challenge->slug)
        ->where('progress.status', ChallengeStatus::NotStarted));
});

test('the reference solution is withheld on a first visit', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $response = $this->actingAs($user)->get(route('challenges.show', [$course, $challenge]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('solution.exists', true)
        ->where('solution.unlocked', false)
        ->where('solution.code', null)
        ->where('solution.attemptsRequired', Challenge::ATTEMPTS_BEFORE_SOLUTION));
});

test('the reference solution stays locked below the attempt threshold', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    UserChallengeProgress::factory()->create([
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
        'attempts' => Challenge::ATTEMPTS_BEFORE_SOLUTION - 1,
    ]);

    $response = $this->actingAs($user)->get(route('challenges.show', [$course, $challenge]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('solution.unlocked', false)
        ->where('solution.code', null));
});

test('the reference solution unlocks once the attempt threshold is reached', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    UserChallengeProgress::factory()->create([
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
        'attempts' => Challenge::ATTEMPTS_BEFORE_SOLUTION,
    ]);

    $response = $this->actingAs($user)->get(route('challenges.show', [$course, $challenge]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('solution.unlocked', true)
        ->where('solution.code', $challenge->solution_code));
});

test('completing a challenge unlocks the reference solution immediately', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    UserChallengeProgress::factory()->completed()->create([
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
        'attempts' => 1,
    ]);

    $response = $this->actingAs($user)->get(route('challenges.show', [$course, $challenge]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('solution.unlocked', true)
        ->where('solution.code', $challenge->solution_code));
});

test('a challenge without a reference solution never unlocks one', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->withoutSolution()->create();

    UserChallengeProgress::factory()->completed()->create([
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
        'attempts' => 10,
    ]);

    $response = $this->actingAs($user)->get(route('challenges.show', [$course, $challenge]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('solution.exists', false)
        ->where('solution.unlocked', false)
        ->where('solution.code', null));
});

test('challenge from a different course returns not found', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $otherCourse = Course::factory()->create();
    $challenge = Challenge::factory()->for($otherCourse)->create();

    $response = $this->actingAs($user)->get(route('challenges.show', [$course, $challenge]));

    $response->assertNotFound();
});

test('a mission inherits its courses tier', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->requiring(Plan::Pro)->create();
    $challenge = Challenge::factory()->for($course)->create();

    $this->actingAs($user)
        ->get(route('challenges.show', [$course, $challenge]))
        ->assertForbidden();
});

test('a mission may require more than the course it sits in', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $free = Challenge::factory()->for($course)->create();
    $paid = Challenge::factory()->for($course)->requiring(Plan::Pro)->create();

    $this->actingAs($user)
        ->get(route('challenges.show', [$course, $free]))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('challenges.show', [$course, $paid]))
        ->assertForbidden();
});

test('a paid pilot can open a locked mission', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();
    $course = Course::factory()->requiring(Plan::Pro)->create();
    $challenge = Challenge::factory()->for($course)->create();

    $this->actingAs($user)
        ->get(route('challenges.show', [$course, $challenge]))
        ->assertOk();
});

/**
 * Access is a question about catalogue depth, not about the exact tier: a
 * plan ranked above the one a mission requires reaches it too.
 */
test('a pilot on a higher tier reaches pro missions', function (): void {
    $user = User::factory()->onPlan(Plan::Team)->create();
    $course = Course::factory()->requiring(Plan::Pro)->create();
    $challenge = Challenge::factory()->for($course)->create();

    $this->actingAs($user)
        ->get(route('challenges.show', [$course, $challenge]))
        ->assertOk();
});

test('a starter pilot cannot post an attempt to a locked mission', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->requiring(Plan::Pro)->create();
    $challenge = Challenge::factory()->for($course)->create();

    $response = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight(),
    );

    $response->assertForbidden();

    $this->assertDatabaseMissing('user_challenge_progress', [
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
    ]);
});

test('a paid pilot can post an attempt to a locked mission', function (): void {
    $user = User::factory()->onPlan(Plan::Pro)->create();
    $course = Course::factory()->requiring(Plan::Pro)->create();
    $challenge = Challenge::factory()->for($course)->create();

    $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight(),
    )->assertOk();

    $this->assertDatabaseHas('user_challenge_progress', [
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
    ]);
});

test('an unpublished locked mission is still not found rather than forbidden', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->requiring(Plan::Pro)->create();
    $challenge = Challenge::factory()->for($course)->unpublished()->create();

    $this->actingAs($user)
        ->get(route('challenges.show', [$course, $challenge]))
        ->assertNotFound();
});

test('submitting a run creates progress', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

    $response = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight(),
    );

    $response->assertOk();
    $response->assertJsonPath('result.completed', true);
    $response->assertJsonPath('result.score', 100);
    $response->assertJsonPath('result.stars', 3);
    $response->assertJsonPath('progress.status', ChallengeStatus::Completed->value);
    $response->assertJsonPath('progress.attempts', 1);

    $this->assertDatabaseHas('user_challenge_progress', [
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
        'status' => ChallengeStatus::Completed->value,
        'best_score' => 100,
        'stars' => 3,
        'attempts' => 1,
    ]);
});

test('a client supplied score is ignored', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

    // The old contract; a pilot posting these hoped to award themselves a
    // finished mission without flying one.
    $response = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight([
            'score' => 100,
            'stars' => 3,
            'completed' => true,
            'path' => challengeHoveringPath(),
        ]),
    );

    $response->assertOk();
    $response->assertJsonPath('result.completed', false);
    $response->assertJsonPath('result.stars', 0);
    $this->assertLessThan(100, $response->json('result.score'));
});

test('a run is graded against the waypoints it actually flew', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = challengeWithWaypoint($course);

    $missed = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight(['path' => flightThrough([
            [0, 0.15, 0],
            [20, 2, 20],
            [20, 0.15, 20],
        ])]),
    );

    $missed->assertJsonPath('result.waypointsHit', 0);
    $missed->assertJsonPath('result.completed', false);

    $flown = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight(['path' => flightThrough([
            [0, 0.15, 0],
            [5, 2, 0],
            [5, 0.15, 0],
        ])]),
    );

    $flown->assertJsonPath('result.waypointsHit', 1);
    $flown->assertJsonPath('result.completed', true);
});

/**
 * The path arrives sampled, so a waypoint can fall between two points.
 * Grading measures each segment rather than its endpoints, or a fast
 * pass through a small waypoint would be scored as a miss.
 */
test('a waypoint crossed between two samples still counts', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = challengeWithWaypoint($course);

    $response = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight(['path' => [
            ['t' => 0, 'x' => 0, 'y' => 2, 'z' => 0],
            // Straight through (5, 2, 0) without ever sampling there.
            ['t' => 1, 'x' => 10, 'y' => 2, 'z' => 0],
            ['t' => 2, 'x' => 10, 'y' => 0.15, 'z' => 0],
        ]]),
    );

    $response->assertJsonPath('result.waypointsHit', 1);
});

test('flying through solid geometry counts as a collision', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create([
        'max_score' => 100,
        'environment' => [
            'start' => ['x' => 0, 'y' => 0.5, 'z' => 0],
            'obstacles' => [
                ['type' => 'box', 'x' => 5, 'y' => 3, 'z' => 0, 'sx' => 6, 'sy' => 6, 'sz' => 6],
            ],
            'gates' => [],
            'waypoints' => [],
        ],
    ]);

    $response = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight([
            'collisions' => 0,
            'path' => [
                ['t' => 0, 'x' => 0, 'y' => 3, 'z' => 0],
                ['t' => 1, 'x' => 5, 'y' => 3, 'z' => 0],
                ['t' => 2, 'x' => 10, 'y' => 3, 'z' => 0],
                ['t' => 3, 'x' => 10, 'y' => 0.15, 'z' => 0],
            ],
        ]),
    );

    $response->assertJsonPath('result.collisions', 1);
    // Completion survives the strike, but the clean-flight star does not.
    $response->assertJsonPath('result.stars', 2);
});

test('a run that never lands does not complete', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

    $response = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight(['path' => challengeHoveringPath()]),
    );

    $response->assertJsonPath('result.landed', false);
    $response->assertJsonPath('result.completed', false);
});

test('a run over the time limit is timed out', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

    // Sitting on the pad for longer than the mission allows: the clock
    // runs out even though the drone never went anywhere.
    $loitering = [];

    for ($second = 0; $second <= 75; $second++) {
        $loitering[] = ['t' => $second, 'x' => 0, 'y' => 0.15, 'z' => 0];
    }

    $response = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight(['path' => $loitering]),
    );

    $response->assertJsonPath('result.timedOut', true);
    $response->assertJsonPath('result.completed', false);
});

test('photo targets are matched against where photos were taken', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create([
        'max_score' => 100,
        'success_criteria' => [
            'type' => 'waypoints',
            'waypoints' => [],
            'avoid_collisions' => true,
            'max_time_seconds' => 60,
            'landing_required' => true,
            'photo_targets' => [['x' => 12, 'z' => -4, 'radius' => 3]],
        ],
    ]);

    // Both runs shoot a frame from where they actually are; only the
    // second one bothers to fly over the target first.
    $elsewhere = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight([
            'path' => flightThrough([[0, 0.15, 0], [-30, 4, 30], [-30, 0.15, 30]]),
            'photos' => [['x' => -30, 'y' => 4, 'z' => 30]],
        ]),
    );

    $elsewhere->assertJsonPath('result.photoTargetsHit', 0);
    $elsewhere->assertJsonPath('result.completed', false);

    $onTarget = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight([
            'path' => flightThrough([[0, 0.15, 0], [12.5, 4, -3], [12.5, 0.15, -3]]),
            'photos' => [['x' => 12.5, 'y' => 4, 'z' => -3]],
        ]),
    );

    $onTarget->assertJsonPath('result.photoTargetsHit', 1);
    $onTarget->assertJsonPath('result.completed', true);
});

test('a wash pass is confirmed from the flight path', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create([
        'max_score' => 100,
        'environment' => [
            'start' => ['x' => 0, 'y' => 0.5, 'z' => 0],
            'obstacles' => [],
            'gates' => [],
            'waypoints' => [],
            'carwash' => ['x' => 0, 'z' => 0, 'width' => 4.5, 'height' => 3.5, 'length' => 8],
        ],
        'success_criteria' => [
            'type' => 'waypoints',
            'waypoints' => [],
            'avoid_collisions' => false,
            'max_time_seconds' => 60,
            'landing_required' => false,
            'wash_required' => true,
        ],
    ]);

    $skipped = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight(['path' => flightThrough([
            [20, 2, 20],
            [25, 2, 25],
        ])]),
    );

    $skipped->assertJsonPath('result.washed', false);
    $skipped->assertJsonPath('result.completed', false);

    // Straight down the tunnel's axis, through both sensor mouths.
    $driven = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight(['path' => [
            ['t' => 0, 'x' => 0, 'y' => 1.5, 'z' => 6],
            ['t' => 1, 'x' => 0, 'y' => 1.5, 'z' => 3.5],
            ['t' => 2, 'x' => 0, 'y' => 1.5, 'z' => 0],
            ['t' => 3, 'x' => 0, 'y' => 1.5, 'z' => -3.5],
            ['t' => 4, 'x' => 0, 'y' => 1.5, 'z' => -6],
        ]]),
    );

    $driven->assertJsonPath('result.washed', true);
    $driven->assertJsonPath('result.completed', true);
});

/**
 * The mission's waypoints are rendered by the browser, so their
 * coordinates are on the page for anyone to read. Sitting a handful of
 * samples on top of them used to buy a finished mission outright; a
 * submission now has to look like something the sampler recorded.
 */
test('a path without the simulator s sampling cadence is rejected', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = challengeWithWaypoint($course);

    $response = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight(['path' => [
            ['t' => 0, 'x' => 0, 'y' => 0.15, 'z' => 0],
            ['t' => 0.05, 'x' => 5, 'y' => 2, 'z' => 0],
            ['t' => 0.1, 'x' => 0, 'y' => 0.15, 'z' => 0],
            // Nothing at all for the next eight seconds.
            ['t' => 8, 'x' => 0, 'y' => 0.15, 'z' => 0],
        ]]),
    );

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('path');
    $this->assertDatabaseEmpty('user_challenge_progress');
});

test('a path that runs backwards in time is rejected', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

    $response = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight(['path' => [
            ['t' => 0, 'x' => 0, 'y' => 0.15, 'z' => 0],
            ['t' => 0.5, 'x' => 0, 'y' => 1.5, 'z' => 0],
            ['t' => 0.2, 'x' => 0, 'y' => 0.15, 'z' => 0],
        ]]),
    );

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('path');
});

/**
 * The clock on a run is the client's. Covering ground therefore has to
 * cost what the airframe would charge for it, or a pilot could claim to
 * have crossed the map in a fraction of a second and collect the speed
 * star for it.
 */
test('a run is charged the time the flight would have taken', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create([
        'max_score' => 100,
        'success_criteria' => [
            'type' => 'waypoints',
            'waypoints' => [],
            'avoid_collisions' => true,
            'max_time_seconds' => 10,
            'landing_required' => true,
        ],
    ]);

    // Three hundred metres of flying, claimed at a tenth of a second a
    // sample. The envelope says this is a minute of flight on a mission
    // that allows ten seconds.
    $sprint = [['t' => 0.0, 'x' => 0, 'y' => 0.15, 'z' => 0]];

    for ($sample = 1; $sample <= 20; $sample++) {
        $sprint[] = [
            't' => $sample * 0.1,
            'x' => $sample % 2 === 0 ? 0 : 150,
            'y' => 0.15,
            'z' => 0,
        ];
    }

    $response = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight(['path' => $sprint]),
    );

    $response->assertOk();
    $this->assertGreaterThan(2, $response->json('result.elapsedSeconds'));
    $response->assertJsonPath('result.timedOut', true);
    $response->assertJsonPath('result.completed', false);
});

/**
 * Waypoints are credited along the segment between two samples, because
 * a real flight can cross one without landing a sample inside it. The
 * same has to hold for the walls, or a path is only solid where it was
 * sampled and can step straight over a building.
 */
test('passing through an obstacle between two samples counts as a collision', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create([
        'max_score' => 100,
        'environment' => [
            'start' => ['x' => 0, 'y' => 0.5, 'z' => 0],
            'obstacles' => [
                ['type' => 'box', 'x' => 5, 'y' => 3, 'z' => 0, 'sx' => 6, 'sy' => 6, 'sz' => 6],
            ],
            'gates' => [],
            'waypoints' => [],
        ],
    ]);

    $response = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight([
            'collisions' => 0,
            'path' => [
                // Straight through the middle of the box without ever
                // sampling inside it.
                ['t' => 0, 'x' => 0, 'y' => 3, 'z' => 0],
                ['t' => 1, 'x' => 10, 'y' => 3, 'z' => 0],
                ['t' => 2, 'x' => 10, 'y' => 0.15, 'z' => 0],
            ],
        ]),
    );

    $response->assertJsonPath('result.collisions', 1);
});

/**
 * The tunnel is placed by rotating it about Y, and the check has to undo
 * that rotation the way the scene applied it. Undoing it backwards is
 * invisible at right angles, where the two agree by symmetry, and fails
 * an honest pass at every other angle.
 */
test('a wash pass through a rotated tunnel is confirmed', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $angle = M_PI / 4;
    $challenge = Challenge::factory()->for($course)->create([
        'max_score' => 100,
        'environment' => [
            'start' => ['x' => 0, 'y' => 0.5, 'z' => 0],
            'obstacles' => [],
            'gates' => [],
            'waypoints' => [],
            'carwash' => ['x' => 0, 'z' => 0, 'width' => 4.5, 'height' => 3.5, 'length' => 8, 'rotationY' => $angle],
        ],
        'success_criteria' => [
            'type' => 'waypoints',
            'waypoints' => [],
            'avoid_collisions' => false,
            'max_time_seconds' => 60,
            'landing_required' => false,
            'wash_required' => true,
        ],
    ]);

    // Down the tunnel's own axis, turned into world space the way
    // three.js turns the group that carries it.
    $path = [];
    $t = 0.0;

    for ($local = 6.0; $local >= -6.0; $local -= 0.4) {
        $path[] = [
            't' => $t,
            'x' => $local * sin($angle),
            'y' => 1.5,
            'z' => $local * cos($angle),
        ];
        $t += 0.25;
    }

    $response = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight(['path' => $path]),
    );

    $response->assertJsonPath('result.washed', true);
    $response->assertJsonPath('result.completed', true);
});

/**
 * A camera mission has no waypoints to fly, so the photo positions are
 * the whole submission. Tying them back to the path is what stops the
 * target coordinates — which the browser is given, to draw them — from
 * being the entire cost of clearing one.
 */
test('a photo taken where the drone never went does not count', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create([
        'max_score' => 100,
        'success_criteria' => [
            'type' => 'waypoints',
            'waypoints' => [],
            'avoid_collisions' => true,
            'max_time_seconds' => 60,
            'landing_required' => true,
            'min_photos' => 1,
            'photo_targets' => [['x' => 12, 'z' => -4, 'radius' => 3]],
        ],
    ]);

    $response = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight([
            // Never leaves the pad, but claims a frame over the target.
            'photos' => [['x' => 12, 'y' => 4, 'z' => -4]],
        ]),
    );

    $response->assertOk();
    $response->assertJsonPath('result.photosTaken', 0);
    $response->assertJsonPath('result.photoTargetsHit', 0);
    $response->assertJsonPath('result.photosMissing', 1);
    $response->assertJsonPath('result.completed', false);
});

test('runs on an unpublished challenge are rejected', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->unpublished()->create();

    $response = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight(),
    );

    $response->assertNotFound();
    $this->assertDatabaseMissing('user_challenge_progress', [
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
    ]);
});

test('runs on a challenge in an unpublished course are rejected', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->unpublished()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $response = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight(),
    );

    $response->assertNotFound();
    $this->assertDatabaseMissing('user_challenge_progress', [
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
    ]);
});

/**
 * Every sample has to be a point before the path can be a recording.
 *
 * The shape check and the cadence check are one pass over the array
 * rather than a validation rule per coordinate, so this covers what the
 * per-coordinate rules used to: a sample missing an axis, carrying
 * something that is not a number on one, or claiming a position off any
 * map, is not a sample.
 *
 * @param  array<int, array<string, mixed>>  $path
 */
test('a path whose samples are not points is rejected', function (array $path): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $response = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight(['path' => $path]),
    );

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('path');
    $this->assertDatabaseEmpty('user_challenge_progress');
})->with('malformed paths');

/**
 * A photo still has to name a place, even though the path is what
 * decides whether the drone was ever at it.
 */
test('a photo taken nowhere is rejected', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $response = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight(['photos' => [['x' => 1, 'y' => 'up', 'z' => 3]]]),
    );

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('photos');
    $this->assertDatabaseEmpty('user_challenge_progress');
});

test('a run without a flight path is rejected', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create();

    $response = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        ['code' => 'async function main(drone) {}', 'collisions' => 0, 'photos' => []],
    );

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('path');
});

test('an incomplete first run marks the challenge in progress', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

    $response = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight(['path' => challengeHoveringPath()]),
    );

    $response->assertOk();
    $response->assertJsonPath('progress.status', ChallengeStatus::InProgress->value);
    $response->assertJsonPath('progress.attempts', 1);

    $this->assertDatabaseHas('user_challenge_progress', [
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
        'status' => ChallengeStatus::InProgress->value,
    ]);
});

test('a completed challenge is not downgraded by a later incomplete run', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

    UserChallengeProgress::factory()->completed()->create([
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
    ]);

    $response = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight(['path' => challengeHoveringPath()]),
    );

    $response->assertOk();
    $response->assertJsonPath('progress.status', ChallengeStatus::Completed->value);
    $response->assertJsonPath('progress.attempts', 2);
});

test('best score never decreases across runs', function (): void {
    $user = User::factory()->create();
    $course = Course::factory()->create();
    $challenge = Challenge::factory()->for($course)->create(['max_score' => 100]);

    UserChallengeProgress::factory()->create([
        'user_id' => $user->id,
        'challenge_id' => $challenge->id,
        'best_score' => 95,
        'stars' => 3,
        'attempts' => 1,
    ]);

    $response = $this->actingAs($user)->postJson(
        route('challenges.attempts.store', [$course, $challenge]),
        challengeFlight(['path' => challengeHoveringPath()]),
    );

    $response->assertOk();
    $response->assertJsonPath('progress.bestScore', 95);
    $response->assertJsonPath('progress.stars', 3);
    $response->assertJsonPath('progress.attempts', 2);
});

/**
 * A clean run: lift off, then settle back onto the pad well inside the
 * time limit.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function challengeFlight(array $overrides = []): array
{
    return array_merge([
        'code' => 'async function main(drone) { await drone.takeoff(); await drone.land(); }',
        'collisions' => 0,
        'path' => [
            ['t' => 0, 'x' => 0, 'y' => 0.15, 'z' => 0],
            ['t' => 1, 'x' => 0, 'y' => 1.5, 'z' => 0],
            ['t' => 2, 'x' => 0, 'y' => 0.15, 'z' => 0],
        ],
        'photos' => [],
    ], $overrides);
}

/**
 * A run that takes off and stays up: it never lands, so it never
 * completes a mission that requires one.
 *
 * @return array<int, array<string, float|int>>
 */
function challengeHoveringPath(): array
{
    return [
        ['t' => 0, 'x' => 0, 'y' => 0.15, 'z' => 0],
        ['t' => 1, 'x' => 0, 'y' => 2, 'z' => 0],
        ['t' => 2, 'x' => 0, 'y' => 2, 'z' => 0],
    ];
}

/**
 * A sampled flight along the given corners, at a rate and a speed the
 * simulator could have produced.
 *
 * Grading rejects a path that no client could have recorded, so a
 * fixture describing a route has to be flown rather than sketched: the
 * corners say where the run went, and this fills in the samples between
 * them.
 *
 * @param  array<int, array{0: float|int, 1: float|int, 2: float|int}>  $corners
 * @return array<int, array<string, float>>
 */
function flightThrough(array $corners): array
{
    $interval = 0.25;
    $speed = 6.0;

    $path = [['t' => 0.0, 'x' => (float) $corners[0][0], 'y' => (float) $corners[0][1], 'z' => (float) $corners[0][2]]];
    $t = 0.0;
    $counter = count($corners);

    for ($i = 1; $i < $counter; $i++) {
        [$fromX, $fromY, $fromZ] = $corners[$i - 1];
        [$toX, $toY, $toZ] = $corners[$i];

        $distance = sqrt(($toX - $fromX) ** 2 + ($toY - $fromY) ** 2 + ($toZ - $fromZ) ** 2);
        $steps = max(1, (int) ceil($distance / ($speed * $interval)));

        for ($step = 1; $step <= $steps; $step++) {
            $fraction = $step / $steps;
            $t += $interval;

            $path[] = [
                't' => $t,
                'x' => $fromX + ($toX - $fromX) * $fraction,
                'y' => $fromY + ($toY - $fromY) * $fraction,
                'z' => $fromZ + ($toZ - $fromZ) * $fraction,
            ];
        }
    }

    return $path;
}

/** A mission with a single waypoint five metres out along +X. */
function challengeWithWaypoint(Course $course): Challenge
{
    return Challenge::factory()->for($course)->create([
        'max_score' => 100,
        'success_criteria' => [
            'type' => 'waypoints',
            'waypoints' => [['x' => 5, 'y' => 2, 'z' => 0, 'radius' => 1.5]],
            'avoid_collisions' => true,
            'max_time_seconds' => 60,
            'landing_required' => true,
        ],
    ]);
}
