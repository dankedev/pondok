<?php

namespace App\Actions\Konten;

use App\Http\Middleware\PreviewPortalTheme;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * URL pratinjau tema (F111): route portal + query `pratinjau-tema=<slug>`, bertanda tangan 30 menit.
 * Root dari `APP_URL` (sama dengan `ForcePortalRootUrl`), bukan host request admin, supaya tanda tangan sah di portal.
 */
class BuildThemePreviewUrl
{
    /**
     * @param  array<string, mixed>  $parameters  parameter route tujuan
     */
    public function handle(string $slug, string $route = 'portal.home', array $parameters = []): string
    {
        URL::forceRootUrl(rtrim((string) config('app.url'), '/'));

        try {
            $url = URL::temporarySignedRoute($route, now()->addMinutes(30), [...$parameters, PreviewPortalTheme::QUERY => $slug]);

            // Route `/` menghasilkan root tanpa garis miring; request.url() juga membuangnya, jadi tanda tangan tetap sah.
            return parse_url($url, PHP_URL_PATH) === null ? Str::replaceFirst('?', '/?', $url) : $url;
        } finally {
            URL::forceRootUrl(null);
        }
    }
}
