<?php

namespace App\Actions\Konten;

use InvalidArgumentException;

/**
 * Daftar tema portal: tema resmi (satu entri per subfolder `config('pondok.themes_path')`, bawaan `resources/themes`, F102)
 * dan tema terpasang dari katalog (`config('pondok.installed_themes_path')`, F103).
 *
 * Tema yang gagal validasi tetap muncul dengan `valid = false` dan alasannya, supaya bisa ditampilkan
 * nonaktif di Situs > Tampilan. Hanya folder terpasang bernama slug sah yang terdaftar (`_staging` tidak), dan tema resmi
 * menang atas folder terpasang berslug sama. `default` selalu di urutan pertama.
 */
class ListPortalThemes
{
    public function __construct(
        private readonly ValidatePortalTheme $validate,
        private readonly ResolveThemePath $paths,
        private readonly CheckThemeAssetsRuntime $assets,
    ) {}

    /**
     * @return list<array{slug: string, name: string, description: string, valid: bool, error: ?string, source: string, version: ?string}>
     */
    public function handle(): array
    {
        $entries = [];

        foreach ($this->slugs('themes_path', false) as $slug) {
            $entries[$slug] = $this->entry($slug, rtrim((string) config('pondok.themes_path'), '/')."/{$slug}/theme.json", 'official');
        }

        foreach ($this->slugs('installed_themes_path', true) as $slug) {
            $path = $this->paths->handle($slug);

            if (! isset($entries[$slug]) && $path !== null) {
                $entries[$slug] = $this->entry($slug, $path, ResolveThemePath::isLocal($slug) ? 'local' : 'installed');
            }
        }

        usort($entries, fn (array $a, array $b): int => [$a['slug'] !== 'default', $a['slug']] <=> [$b['slug'] !== 'default', $b['slug']]);

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function slugs(string $configKey, bool $onlyValidSlugs): array
    {
        $slugs = array_map('basename', glob(rtrim((string) config("pondok.{$configKey}"), '/').'/*', GLOB_ONLYDIR) ?: []);

        // Folder tema bawaan tampil apa adanya (F102); folder terpasang disaring agar `_staging` dan nama non-slug tak muncul.
        return $onlyValidSlugs ? array_values(array_filter($slugs, ResolveThemePath::isSlug(...))) : array_values($slugs);
    }

    /**
     * @return array{slug: string, name: string, description: string, valid: bool, error: ?string, source: string, version: ?string}
     */
    private function entry(string $slug, string $path, string $source): array
    {
        try {
            $manifest = $this->validate->handle($path);
            $this->assets->handle($path, $manifest);

            return ['slug' => $slug, 'name' => $manifest['name'], 'description' => $manifest['description'], 'valid' => true, 'error' => null, 'source' => $source, 'version' => $manifest['version']];
        } catch (InvalidArgumentException $e) {
            return ['slug' => $slug, 'name' => $slug, 'description' => '', 'valid' => false, 'error' => $e->getMessage(), 'source' => $source, 'version' => null];
        }
    }
}
