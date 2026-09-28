<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RedirectToCanonicalHost
{
    /**
     * Send the www. copy of the site to the one APP_URL names, permanently.
     *
     * DNS answers for both names and the host serves the same application on
     * each, so without this every page exists twice — www.droneverse.cloud
     * and droneverse.cloud — and search engines split the two copies' links
     * and rankings between them. A 301 folds them into one. It lives here
     * rather than in the web server's configuration so that it ships with the
     * code, whatever that server is.
     *
     * Only the www. twin of APP_URL's host is redirected, not every host that
     * is not APP_URL's. A misconfigured APP_URL then costs the www. redirect
     * and nothing else, instead of sending the whole site somewhere else.
     *
     * Only safe methods are redirected. A browser replays a redirected POST as
     * a GET, which would drop the form it was submitting.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appUrl = config()->string('app.url');
        $canonicalHost = parse_url($appUrl, PHP_URL_HOST);

        if ($request->isMethodSafe() && is_string($canonicalHost) && $request->getHost() === 'www.'.$canonicalHost) {
            return redirect()->to(mb_rtrim($appUrl, '/').$request->getRequestUri(), 301);
        }

        return $next($request);
    }
}
