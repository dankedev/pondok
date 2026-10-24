<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan panel admin (F086): header dasar portal ditambah CSP report-only.
 * Browser hanya mencatat pelanggaran di console; Livewire/Alpine butuh `unsafe-eval` dan style inline,
 * pratinjau unggah FilePond butuh gambar dan worker `blob:`.
 */
class SetAdminSecurityHeaders
{
    public const CONTENT_SECURITY_POLICY_REPORT_ONLY = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
        ."style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; worker-src 'self' blob:; "
        ."object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        SetPortalSecurityHeaders::setBaseHeaders($response);
        $response->headers->set('Content-Security-Policy-Report-Only', self::CONTENT_SECURITY_POLICY_REPORT_ONLY);

        return $response;
    }
}
