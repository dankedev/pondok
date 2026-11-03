<?php

namespace App\Actions\Konten;

use Throwable;

/**
 * Path berkas aset tema untuk route `theme-asset` (F105), atau null (404) untuk apa pun yang tidak persis dilayani.
 *
 * Syarat: slug/hash12/path lolos regex; slug = tema terpasang (bukan tema resmi); hash12 = 12 hex pertama sha256 `theme.json` terpasang;
 * path tercantum di `assets` manifest; folder aset dan berkasnya bukan symlink dan `realpath` berada di dalam folder aset itu.
 */
class ResolveThemeAssetPath
{
    private const HASH_PATTERN = '/^[0-9a-f]{12}\z/';

    private const PATH_PATTERN = '/^(?:theme\.css|fonts\/[a-z0-9][a-z0-9-]{0,40}\.woff2)\z/';

    public function __construct(private readonly ValidatePortalTheme $validate) {}

    public function handle(mixed $slug, mixed $hash, mixed $path): ?string
    {
        if (! ResolveThemePath::isSlug($slug) || ResolveThemePath::isOfficial($slug)
            || ! is_string($hash) || preg_match(self::HASH_PATTERN, $hash) !== 1
            || ! is_string($path) || preg_match(self::PATH_PATTERN, $path) !== 1) {
            return null;
        }

        $directory = rtrim((string) config('pondok.installed_themes_path'), '/')."/{$slug}";
        $manifestPath = "{$directory}/theme.json";

        if (! is_file($manifestPath) || is_link($manifestPath) || substr((string) hash_file('sha256', $manifestPath), 0, 12) !== $hash) {
            return null;
        }

        try {
            $manifest = $this->validate->handle($manifestPath);
        } catch (Throwable) {
            return null;
        }

        $declared = [...(isset($manifest['assets']['css']) ? [$manifest['assets']['css']] : []), ...($manifest['assets']['fonts'] ?? [])];

        if (! in_array($path, array_column($declared, 'path'), true)) {
            return null;
        }

        return $this->contained("{$directory}/assets", $hash, $path);
    }

    private function contained(string $assets, string $hash, string $path): ?string
    {
        $root = "{$assets}/{$hash}";
        $file = "{$root}/{$path}";

        foreach ([$assets, $root, dirname($file), $file] as $part) {
            if (is_link($part)) {
                return null;
            }
        }

        $realRoot = realpath($root);
        $realFile = realpath($file);

        if ($realRoot === false || $realFile === false || ! is_file($realFile) || ! str_starts_with($realFile, $realRoot.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $realFile;
    }
}
