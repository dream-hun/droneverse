<?php

declare(strict_types=1);

use App\Models\Challenge;
use App\Models\Course;
use App\Models\Quiz;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    config(['app.url' => 'https://droneverse.cloud']);
});

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, string|null> each listed address, with its lastmod
 */
function sitemapEntries(TestResponse $response): array
{
    $sitemap = simplexml_load_string((string) $response->getContent());

    throw_unless($sitemap instanceof SimpleXMLElement, RuntimeException::class, 'The sitemap is not well-formed XML.');

    $entries = [];

    foreach ($sitemap->url as $url) {
        $entries[(string) $url->loc] = isset($url->lastmod) ? (string) $url->lastmod : null;
    }

    return $entries;
}

/**
 * Drone Basics has a written guide in config/course-docs.php and Night
 * Flying does not, so only one of them has a guide page to list.
 */
test('the sitemap lists every public page at its canonical address and nothing else', function (): void {
    Course::factory()->create(['slug' => 'drone-basics', 'order' => 0]);
    Course::factory()->create(['slug' => 'night-flying', 'order' => 1]);
    Course::factory()->unpublished()->create(['slug' => 'draft-course', 'order' => 2]);

    $entries = sitemapEntries($this->get(route('sitemap', absolute: false)));

    expect(array_keys($entries))->toBe([
        'https://droneverse.cloud/',
        'https://droneverse.cloud/courses',
        'https://droneverse.cloud/courses/drone-basics',
        'https://droneverse.cloud/courses/drone-basics/docs',
        'https://droneverse.cloud/courses/night-flying',
        'https://droneverse.cloud/docs',
        'https://droneverse.cloud/pricing',
        'https://droneverse.cloud/terms',
        'https://droneverse.cloud/privacy',
    ]);
});

dataset('latest change', [
    'the course itself' => ['2026-03-02 10:00:00', '2026-01-01 00:00:00', '2026-02-01 00:00:00'],
    'one of its missions' => ['2026-01-01 00:00:00', '2026-03-02 10:00:00', '2026-02-01 00:00:00'],
    'one of its quizzes' => ['2026-01-01 00:00:00', '2026-02-01 00:00:00', '2026-03-02 10:00:00'],
]);

test('a course page is dated by the latest change to anything it shows', function (string $course, string $mission, string $quiz): void {
    $model = Course::factory()->create(['slug' => 'drone-basics']);
    Challenge::factory()->for($model)->create(['updated_at' => $mission]);
    Quiz::factory()->for($model)->create(['updated_at' => $quiz]);
    Course::query()->whereKey($model->id)->update(['updated_at' => $course]);

    $entries = sitemapEntries($this->get(route('sitemap', absolute: false)));

    expect($entries['https://droneverse.cloud/courses/drone-basics'] ?? null)->toBe('2026-03-02T10:00:00+00:00');
})->with('latest change');

test('the legal pages are dated by the revision in force and the rest are left undated', function (): void {
    config(['legal.effective.terms' => '2026-08-21', 'legal.effective.privacy' => '2026-07-01']);

    $entries = sitemapEntries($this->get(route('sitemap', absolute: false)));

    expect($entries)->toMatchArray([
        'https://droneverse.cloud/terms' => '2026-08-21',
        'https://droneverse.cloud/privacy' => '2026-07-01',
        'https://droneverse.cloud/' => null,
        'https://droneverse.cloud/pricing' => null,
    ]);
});

/**
 * Crawlers fetch the sitemap without cookies, so a session started for each
 * fetch would be a database row written per visit and never read again.
 */
test('the sitemap is served as xml without starting a session', function (): void {
    $response = $this->get(route('sitemap', absolute: false));

    $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    $response->assertHeaderMissing('Set-Cookie');
});
