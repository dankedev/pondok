<?php

namespace App\Actions\Konten;

/**
 * Path `theme.json` untuk satu slug tema (F103): tema resmi (`pondok.themes_path`) menang atas tema terpasang
 * (`pondok.installed_themes_path`). Null untuk slug berbentuk tak sah (tak pernah membentuk path dari masukan liar)
 * atau slug tanpa berkas. Satu titik untuk `ListPortalThemes`, `ResolveActiveTheme`, dan `GetPortalTheme`.
 */
class ResolveThemePath
{
    public const SLUG_PATTERN = '/^[a-z0-9-]{2,40}\z/';

    public static function isSlug(mixed $slug): bool
    {
        return is_string($slug) && preg_match(self::SLUG_PATTERN, $slug) === 1;
    }

    public function handle(mixed $slug): ?string
    {
        if (! self::isSlug($slug)) {
            return null;
        }

        foreach (['themes_path', 'installed_themes_path'] as $key) {
            $path = rtrim((string) config("pondok.{$key}"), '/')."/{$slug}/theme.json";

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
