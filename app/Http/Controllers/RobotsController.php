<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildCanonicalUrl;
use Illuminate\Http\Response;

final class RobotsController extends Controller
{
    /**
     * Tell crawlers what to skip, and where the sitemap is.
     *
     * A route rather than a file in public/, for two reasons. The sitemap has
     * to be named by an absolute URL, which only APP_URL knows. And anywhere
     * but production the answer is to crawl nothing at all: a staging copy
     * that gets indexed competes with the real site for its own name.
     *
     * The disallowed paths are the ones no guest is ever linked to. The
     * signed-in pages a guest can be linked to, like the dashboard in the
     * footer, are left crawlable on purpose: they redirect to the sign-in page,
     * which keeps them out of the index, whereas a URL blocked here can still
     * be indexed, without its content, from the links pointing at it.
     */
    public function __invoke(BuildCanonicalUrl $canonicalUrl): Response
    {
        $rules = app()->isProduction()
            ? [
                'User-agent: *',
                'Disallow: /admin',
                'Disallow: /api/',
                'Disallow: /settings',
                '',
                'Sitemap: '.$canonicalUrl->handle(route('sitemap', absolute: false)),
            ]
            : [
                'User-agent: *',
                'Disallow: /',
            ];

        return response(implode("\n", $rules)."\n")
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
