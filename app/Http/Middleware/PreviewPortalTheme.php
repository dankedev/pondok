<?php

namespace App\Http\Middleware;

use App\Actions\Konten\ResolveThemePath;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pratinjau tema (F111): query `pratinjau-tema=<slug>` dengan tanda tangan URL sah dan slug tema terpasang
 * menjadikan slug itu tema efektif request ini (atribut request, `theme.template` tidak ditulis).
 * Harus terdaftar sebelum `SetPortalCacheHeaders` agar header cache publiknya ditimpa. Selain itu tidak melakukan apa pun.
 */
class PreviewPortalTheme
{
    public const QUERY = 'pratinjau-tema';

    public const ATTRIBUTE = 'portal.preview_theme';

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->query(self::QUERY);

        if (! is_string($slug) || ! $request->hasValidSignature() || app(ResolveThemePath::class)->handle($slug) === null) {
            return $next($request);
        }

        $request->attributes->set(self::ATTRIBUTE, $slug);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
