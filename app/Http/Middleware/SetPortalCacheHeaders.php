<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cache HTTP publik + ETag/304 untuk respons 200 portal (grup tanpa sesi/cookie).
 * Status lain dan `pondok.portal.cache_max_age = 0` tidak disentuh.
 */
class SetPortalCacheHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $maxAge = (int) config('pondok.portal.cache_max_age');

        if (! $request->isMethodCacheable() || $response->getStatusCode() !== 200 || $maxAge <= 0) {
            return $response;
        }

        $response->setPublic();
        $response->setMaxAge($maxAge);
        $response->setEtag(md5((string) $response->getContent()));
        $response->isNotModified($request);

        return $response;
    }
}
