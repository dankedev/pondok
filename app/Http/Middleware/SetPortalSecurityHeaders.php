<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan untuk semua respons route portal (termasuk 404 di dalam grup portal).
 * CSP mengizinkan embed YouTube nocookie; tidak dikirim saat Vite dev server berjalan.
 */
class SetPortalSecurityHeaders
{
    public const CONTENT_SECURITY_POLICY = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' https: http:; font-src 'self'; frame-src https://www.youtube-nocookie.com; connect-src 'self'; "
        ."object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if (config('pondok.portal.csp_enabled') && ! Vite::isRunningHot()) {
            $response->headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
        }

        return $response;
    }
}
