<?php

namespace App\Actions\Konten\Concerns;

/**
 * Aturan URL publik profil lembaga, dipakai saat tulis (Action) dan saat baca
 * (`GetSchoolProfile`). Karakter kontrol dan spasi ditolak di posisi mana pun,
 * karena browser membuang tab/newline sehingga `/<TAB>/evil.test` menjadi `//evil.test`.
 */
trait ValidatesPublicUrls
{
    protected function isSafeAbsoluteUrl(mixed $url): bool
    {
        if (! is_string($url) || $url === '' || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true) && $host !== '';
    }

    protected function isSafeInternalPath(mixed $path): bool
    {
        if (! is_string($path) || $path === '' || preg_match('/[\x00-\x20\x7F]/', $path) === 1) {
            return false;
        }

        return $path[0] === '/'
            && ! in_array($path[1] ?? '', ['/', '\\'], true)
            && ! str_contains($path, '\\');
    }
}
