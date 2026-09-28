<?php

declare(strict_types=1);

namespace App\Actions;

/**
 * The one address a public page is known by, whichever host it was asked for on.
 *
 * `route()` answers with the host the request arrived on, so the same page is
 * https://droneverse.cloud/pricing to one visitor and
 * https://www.droneverse.cloud/pricing to another, and a search engine treats
 * the two as rival copies of each other. Everything that names a page to a
 * crawler — the canonical link, the social card, the sitemap, robots.txt —
 * builds the address here instead, from APP_URL, so they all name the same one.
 */
final readonly class BuildCanonicalUrl
{
    /**
     * @param  string  $path  a path on this site, as `route($name, absolute: false)` returns it
     */
    public function handle(string $path): string
    {
        return mb_rtrim(config()->string('app.url'), '/').'/'.mb_ltrim($path, '/');
    }
}
