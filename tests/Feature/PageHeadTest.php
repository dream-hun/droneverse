<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\User;
use Dom\HTMLDocument;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

/*
 * Without an SSR server, the HTML a page is served with is the only <head>
 * that a crawler which skips JavaScript, or a link unfurler, ever reads.
 * These tests parse that HTML rather than asserting on Inertia props, because
 * the failure they guard against is invisible in the props: a head that only
 * appears once React has booted.
 */

beforeEach(function (): void {
    config(['app.url' => 'https://droneverse.cloud', 'app.name' => 'DroneVerse']);
});

/**
 * The served `<head>`, parsed the way a browser parses it, before any script runs.
 *
 * @param  TestResponse<Response>  $response
 * @return array{title: string|null, meta: array<string, string>, canonical: string|null, graph: list<array<mixed>>}
 */
function servedHead(TestResponse $response): array
{
    $document = HTMLDocument::createFromString((string) $response->getContent(), LIBXML_NOERROR);

    $meta = [];

    foreach ($document->querySelectorAll('head meta[name], head meta[property]') as $element) {
        $meta[$element->getAttribute('name') ?? $element->getAttribute('property') ?? ''] = $element->getAttribute('content') ?? '';
    }

    $json = $document->querySelector('head script[type="application/ld+json"]')?->textContent;
    $schema = $json === null ? [] : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    $graph = is_array($schema) && is_array($schema['@graph'] ?? null) ? $schema['@graph'] : [];

    return [
        'title' => $document->querySelector('head title')?->textContent,
        'meta' => $meta,
        'canonical' => $document->querySelector('head link[rel=canonical]')?->getAttribute('href'),
        'graph' => array_values(array_filter($graph, is_array(...))),
    ];
}

/**
 * @param  list<array<mixed>>  $graph
 * @return array<mixed>|null
 */
function schemaNode(array $graph, string $type): ?array
{
    return new Collection($graph)->firstWhere('@type', $type);
}

dataset('public pages', [
    'landing' => ['home', 'https://droneverse.cloud/'],
    'catalogue' => ['courses.index', 'https://droneverse.cloud/courses'],
    'manual' => ['docs', 'https://droneverse.cloud/docs'],
    'pricing' => ['pricing', 'https://droneverse.cloud/pricing'],
    'terms' => ['terms', 'https://droneverse.cloud/terms'],
    'privacy' => ['privacy', 'https://droneverse.cloud/privacy'],
    'sign in' => ['login', 'https://droneverse.cloud/login'],
    'sign up' => ['register', 'https://droneverse.cloud/register'],
]);

test('public pages carry their own title, description and canonical address in the served html', function (string $route, string $canonical): void {
    $head = servedHead($this->get(route($route, absolute: false).'?utm_source=newsletter'));

    expect($head['title'])->toEndWith(' - DroneVerse');
    expect(mb_substr_count((string) $head['title'], 'DroneVerse'))->toBe(1);
    expect($head['meta']['description'] ?? '')->not->toBeEmpty();
    expect($head['canonical'])->toBe($canonical);
    expect($head['meta'])->toMatchArray([
        'og:url' => $canonical,
        'og:title' => $head['title'],
        'og:image' => 'https://droneverse.cloud/og-image.png',
        'twitter:card' => 'summary_large_image',
    ]);
})->with('public pages');

test('public pages ask to be indexed in production', function (string $route): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    $head = servedHead($this->get(route($route, absolute: false)));

    expect($head['meta']['robots'] ?? null)->toBe('index, follow, max-image-preview:large');
})->with('public pages');

/**
 * A staging copy that gets indexed competes with the real site for its own
 * name, so everywhere but production says noindex, public pages included.
 */
test('public pages stay out of the index outside production', function (): void {
    $head = servedHead($this->get(route('pricing', absolute: false)));

    expect($head['meta']['robots'] ?? null)->toBe('noindex, nofollow');
});

/**
 * The address a page is indexed under comes from APP_URL. Taking it from the
 * request would make every host that answers for the site, a bare IP address
 * included, a rival copy of it.
 */
test('the canonical address ignores the host a page was requested on', function (): void {
    $head = servedHead($this->get('http://198.51.100.7/pricing'));

    expect($head['canonical'])->toBe('https://droneverse.cloud/pricing');
});

test('pages that do not describe themselves stay out of the index even in production', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    $guest = servedHead($this->get(route('password.request', absolute: false)));
    $pilot = servedHead($this->actingAs(User::factory()->create())->get(route('dashboard', absolute: false)));

    foreach ([$guest, $pilot] as $head) {
        expect($head['title'])->toBe('DroneVerse');
        expect($head['meta']['robots'] ?? null)->toBe('noindex, nofollow');
        expect($head['canonical'])->toBeNull();
        expect($head['meta'])->not->toHaveKey('description');
    }
});

test('a course page is described by its course', function (): void {
    $course = Course::factory()->create([
        'title' => 'Drone Basics',
        'slug' => 'drone-basics',
        'difficulty' => 'beginner',
        'description' => 'Learn to pilot a drone with code.',
    ]);

    $head = servedHead($this->get(route('courses.show', $course, absolute: false)));

    expect($head['title'])->toBe('Drone Basics: beginner drone programming course - DroneVerse');
    expect($head['meta']['description'] ?? null)->toBe('Learn to pilot a drone with code.');
    expect($head['canonical'])->toBe('https://droneverse.cloud/courses/drone-basics');
    expect(schemaNode($head['graph'], 'BreadcrumbList'))->toBe([
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Courses', 'item' => 'https://droneverse.cloud/courses'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Drone Basics', 'item' => 'https://droneverse.cloud/courses/drone-basics'],
        ],
    ]);
});

/**
 * Search results cut a description off at around 160 characters, mid-word.
 * Shortening it here first means the cut falls between words and says so.
 */
test('a long course description is shortened between words', function (): void {
    $description = str_repeat('Thread the gates and land on the pad. ', 6);
    $course = Course::factory()->create(['description' => $description]);

    $shortened = servedHead($this->get(route('courses.show', $course, absolute: false)))['meta']['description'] ?? '';

    expect(mb_strlen($shortened))->toBeLessThanOrEqual(161);
    expect($shortened)->toEndWith('…');
    expect($description)->toStartWith(mb_substr($shortened, 0, -1).' ');
});

test('a course guide is described by its course and names the trail down to it', function (): void {
    config(['course-docs.drone-basics.tagline' => 'Take off, go somewhere, come back down.']);
    $course = Course::factory()->create(['title' => 'Drone Basics', 'slug' => 'drone-basics']);

    $head = servedHead($this->get(route('courses.docs', $course, absolute: false)));

    expect($head['title'])->toBe('Drone Basics guide: commands and examples - DroneVerse');
    expect($head['meta']['description'] ?? '')->toStartWith('Take off, go somewhere, come back down. ');
    expect($head['canonical'])->toBe('https://droneverse.cloud/courses/drone-basics/docs');
    expect(schemaNode($head['graph'], 'BreadcrumbList'))->toBe([
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Courses', 'item' => 'https://droneverse.cloud/courses'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Drone Basics', 'item' => 'https://droneverse.cloud/courses/drone-basics'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => 'Guide', 'item' => 'https://droneverse.cloud/courses/drone-basics/docs'],
        ],
    ]);
});

/**
 * A course title is typed by an author in the admin, and it lands in three
 * places in the head: element text, an attribute, and a script body where
 * a closing tag would end the script early.
 */
test('course text is escaped wherever it lands in the head', function (): void {
    $title = 'Crash <script>alert(1)</script> & "Burn"';
    $course = Course::factory()->create(['title' => $title, 'description' => $title]);

    $response = $this->get(route('courses.show', $course, absolute: false));
    $head = servedHead($response);

    $response->assertDontSee('<script>alert(1)</script>', false);
    expect($head['title'])->toStartWith($title.':');
    expect($head['meta']['description'] ?? null)->toBe($title);
    expect(schemaNode($head['graph'], 'BreadcrumbList'))->toHaveKey('itemListElement.1.name', $title);
});

test('public pages name the site and its logo for search results', function (): void {
    $head = servedHead($this->get(route('home', absolute: false)));

    expect(schemaNode($head['graph'], 'WebSite'))->toMatchArray([
        'name' => 'DroneVerse',
        'url' => 'https://droneverse.cloud/',
    ]);
    expect(schemaNode($head['graph'], 'Organization'))->toMatchArray([
        'name' => 'DroneVerse',
        'url' => 'https://droneverse.cloud/',
        'logo' => 'https://droneverse.cloud/apple-touch-icon.png',
    ]);
});

/**
 * The root template prints the head once; after that, Inertia swaps it on
 * every client-side visit from the `head` prop. Without the prop, the first
 * page's canonical address would follow the visitor around the site.
 */
test('the head travels with the page for client-side visits', function (): void {
    $response = $this->get(route('pricing', absolute: false));

    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->where('head', fn (Collection $head): bool => $head->contains(
            '<link rel="canonical" href="https://droneverse.cloud/pricing" data-inertia="canonical">',
        )));
});

test('the social card image is published at the size the head declares', function (): void {
    $png = (string) file_get_contents(public_path('og-image.png'));
    $header = unpack('Nwidth/Nheight', mb_substr($png, 16, 8, '8bit')) ?: [];

    expect(mb_substr($png, 1, 3, '8bit'))->toBe('PNG');
    expect($header)->toBe(['width' => 1200, 'height' => 630]);
});
