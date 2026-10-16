<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * URL absolut buatan aplikasi di respons portal (canonical, og:url, sitemap, robots,
 * navigasi) dibangun dari `APP_URL`, bukan dari header `Host`, karena respons di-cache publik.
 */
class ForcePortalRootUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $root = rtrim((string) config('app.url'), '/');

        if ($root !== '') {
            $scheme = parse_url($root, PHP_URL_SCHEME);

            if (in_array($scheme, ['http', 'https'], true)) {
                URL::forceRootUrl($root);
                URL::forceScheme($scheme);
            } else {
                // APP_URL tanpa skema: pakai skema permintaan, tanpa forceScheme.
                URL::forceRootUrl($request->getScheme().'://'.$root);
            }
        }

        return $next($request);
    }
}
