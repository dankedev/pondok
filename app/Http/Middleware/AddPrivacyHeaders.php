<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header privasi untuk halaman publik yang memuat data pribadi minimal
 * (verifikasi surat): tidak diindeks, tanpa referrer, tidak di-cache.
 *
 * Didaftarkan di daftar prioritas sebelum `EnsureModuleIsEnabled`, sehingga
 * respons 403 (modul mati) dan 429 (throttle) juga membawa header ini.
 */
class AddPrivacyHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
