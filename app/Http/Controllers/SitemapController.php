<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildSitemap;
use Illuminate\Http\Response;

final class SitemapController extends Controller
{
    /**
     * The public pages, listed for crawlers; robots.txt points here.
     */
    public function __invoke(BuildSitemap $sitemap): Response
    {
        return response()
            ->view('sitemap', ['entries' => $sitemap->handle()])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
