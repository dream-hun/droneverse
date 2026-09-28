<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * The `<head>` a page is found and shared by: its title and description, the
 * address it is indexed under, whether it should be indexed at all, the card a
 * link to it unfurls into, and the structured data naming the site.
 *
 * Built on the server as finished markup so that it is in the HTML whether or
 * not an SSR server is running. Without one, the only `<head>` a crawler or a
 * link unfurler that skips JavaScript ever reads is the one the root template
 * prints. The template prints these elements, and the same list travels as
 * the `head` prop: `serverHead` in resources/js/app.tsx hands it to Inertia's
 * head manager, which swaps it for the next page's on a client-side visit.
 * Every element carries its own `data-inertia` key, which is how the head
 * manager recognises the printed copy as the one it manages rather than
 * appending a second one beside it.
 *
 * A page with a canonical path is a page meant to be found. A page without one
 * is not, and gets `noindex` — which is every page that does not ask, since
 * that is the default HandleInertiaRequests shares. So does every page outside
 * production, so that a staging copy cannot compete with the real site.
 */
final readonly class BuildPageHead
{
    /** The social card, 1200 × 630, published under public/. */
    private const string IMAGE_PATH = 'og-image.png';

    private const string IMAGE_ALT = 'DroneVerse: learn to fly, one line at a time. Beside the headline, a short JavaScript program that flies a drone.';

    /** At least 112 pixels square, which is what Google asks of a logo. */
    private const string LOGO_PATH = 'apple-touch-icon.png';

    public function __construct(private BuildCanonicalUrl $canonicalUrl) {}

    /**
     * @param  string|null  $title  the page's own title, which the site name is added after; pageTitle() in resources/js/lib/page-title.ts leaves the result alone when Inertia passes it through again in the browser
     * @param  string|null  $path  the page's canonical path, as `route($name, absolute: false)` returns it
     * @param  array<string, string>  $breadcrumbs  the trail down to this page, as path => name, published as a BreadcrumbList
     * @return list<string>
     */
    public function handle(?string $title = null, ?string $description = null, ?string $path = null, array $breadcrumbs = []): array
    {
        $siteName = config()->string('app.name');
        $title = $title === null ? $siteName : sprintf('%s - %s', $title, $siteName);

        if ($path === null) {
            return [
                $this->title($title),
                $this->meta('name', 'robots', 'noindex, nofollow'),
            ];
        }

        $url = $this->canonicalUrl->handle($path);

        return array_values(array_filter([
            $this->title($title),
            $description === null ? null : $this->meta('name', 'description', $description),
            $this->meta('name', 'robots', app()->isProduction() ? 'index, follow, max-image-preview:large' : 'noindex, nofollow'),
            sprintf('<link rel="canonical" href="%s" data-inertia="canonical">', e($url)),
            $this->meta('property', 'og:type', 'website'),
            $this->meta('property', 'og:site_name', $siteName),
            $this->meta('property', 'og:title', $title),
            $description === null ? null : $this->meta('property', 'og:description', $description),
            $this->meta('property', 'og:url', $url),
            $this->meta('property', 'og:image', $this->canonicalUrl->handle(self::IMAGE_PATH)),
            $this->meta('property', 'og:image:width', '1200'),
            $this->meta('property', 'og:image:height', '630'),
            $this->meta('property', 'og:image:alt', self::IMAGE_ALT),
            $this->meta('name', 'twitter:card', 'summary_large_image'),
            $this->structuredData($siteName, $breadcrumbs),
        ]));
    }

    private function title(string $title): string
    {
        return sprintf('<title data-inertia="title">%s</title>', e($title));
    }

    private function meta(string $attribute, string $key, string $content): string
    {
        return sprintf('<meta %s="%s" content="%s" data-inertia="%s">', $attribute, $key, e($content), $key);
    }

    /**
     * The site's identity, on every page that can be indexed, and the trail
     * down to this one when it has one.
     *
     * WebSite is what Google reads the site name in its results from, and
     * Organization is where it looks for the logo. Both belong on the home
     * page; they are on every public page because they cost a few hundred
     * bytes and it spares each controller from knowing which page is home.
     *
     * `JSON_HEX_TAG` escapes `<` and `>`, so no course title can close the
     * script element early.
     *
     * @param  array<string, string>  $breadcrumbs
     */
    private function structuredData(string $siteName, array $breadcrumbs): string
    {
        $home = $this->canonicalUrl->handle('/');

        $graph = [
            [
                '@type' => 'WebSite',
                '@id' => $home.'#website',
                'name' => $siteName,
                'url' => $home,
                'publisher' => ['@id' => $home.'#organization'],
            ],
            [
                '@type' => 'Organization',
                '@id' => $home.'#organization',
                'name' => $siteName,
                'url' => $home,
                'logo' => $this->canonicalUrl->handle(self::LOGO_PATH),
            ],
        ];

        if ($breadcrumbs !== []) {
            $trail = [];

            foreach ($breadcrumbs as $path => $name) {
                $trail[] = [
                    '@type' => 'ListItem',
                    'position' => count($trail) + 1,
                    'name' => $name,
                    'item' => $this->canonicalUrl->handle($path),
                ];
            }

            $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $trail];
        }

        return sprintf(
            '<script type="application/ld+json" data-inertia="schema">%s</script>',
            json_encode(
                ['@context' => 'https://schema.org', '@graph' => $graph],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR,
            ),
        );
    }
}
