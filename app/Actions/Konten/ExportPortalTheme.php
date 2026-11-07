<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\WritesInstalledThemes;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Ekspor tema `lokal-*` terpasang menjadi folder paket penerbit (F111, AC-11): `handle(sumber, folder, as)` menulis
 * `<folder>/<as>/` berisi `theme.json`, `theme.css`, dan `fonts/*.woff2` (masukan `pondok:theme-sign`). Slug diganti ke `<as>`,
 * scope CSS ikut diganti (sha256 dan size dihitung ulang), versi `1.0.0`. Tema sumber hanya dibaca; penulisan lewat staging di
 * dalam `<folder>` lalu rename, jadi gagal = tidak ada yang tersisa (folder yang baru dibuat ikut dihapus).
 */
class ExportPortalTheme
{
    use WritesInstalledThemes;

    public function __construct(
        private readonly ValidatePortalTheme $validate,
        private readonly ValidateThemePackage $package,
        private readonly ResolveThemePath $paths,
    ) {}

    /**
     * @return string path folder paket hasil ekspor
     *
     * @throws InvalidArgumentException
     */
    public function handle(string $sourceSlug, string $folder, string $as): string
    {
        $folder = rtrim($folder, '/');

        if (! ResolveThemePath::isSlug($as) || ResolveThemePath::isLocal($as) || ResolveThemePath::isOfficial($as)) {
            $this->reject('pondok.theme_export.as_invalid');
        }

        $sourcePath = ResolveThemePath::isSlug($sourceSlug) && ResolveThemePath::isLocal($sourceSlug) && ! ResolveThemePath::isOfficial($sourceSlug)
            ? $this->paths->handle($sourceSlug)
            : null;

        if ($sourcePath === null) {
            $this->reject('pondok.theme_export.source_invalid');
        }

        if ($folder === '' || file_exists("{$folder}/{$as}") || is_link("{$folder}/{$as}")) {
            $this->reject('pondok.theme_export.destination_exists', ['path' => "{$folder}/{$as}"]);
        }

        // tujuan tidak boleh di dalam folder tema terpasang: tema tanpa tanda tangan akan langsung tampak terpasang
        if ($this->isInside($folder, (string) config('pondok.installed_themes_path'))) {
            $this->reject('pondok.theme_export.destination_invalid', ['path' => $folder]);
        }

        $source = $this->validate->handle($sourcePath);
        $raw = json_decode((string) file_get_contents($sourcePath), true);
        $files = isset($source['assets']) ? $this->installedAssetFiles(dirname($sourcePath), $source) : [];

        $this->rescopeCss($raw, $files, $source['slug'], $as);

        $bytes = $this->encodeManifest([...$raw, 'slug' => $as, 'version' => '1.0.0']);
        $firstCreated = $this->firstMissingAncestor($folder);
        $staging = "{$folder}/_staging-".Str::random(8);

        try {
            $this->write("{$staging}/{$as}", $bytes, $files);

            $manifest = $this->validate->handle("{$staging}/{$as}/theme.json");

            if (isset($manifest['assets'])) {
                $this->package->handle("{$staging}/{$as}", $manifest);
            }

            if (! @rename("{$staging}/{$as}", "{$folder}/{$as}")) {
                $this->reject('pondok.theme_export.write_failed');
            }
        } catch (Throwable $exception) {
            File::deleteDirectory($firstCreated ?? $staging);

            throw $exception;
        }

        File::deleteDirectory($staging);

        return "{$folder}/{$as}";
    }

    /**
     * Folder pertama dari `$path` ke atas yang belum ada (ikut dihapus bila ekspor gagal), atau null bila `$path` sudah ada.
     */
    private function firstMissingAncestor(string $path): ?string
    {
        $missing = null;

        while ($path !== '' && $path !== '.' && $path !== '/' && ! file_exists($path) && ! is_link($path)) {
            $missing = $path;
            $path = dirname($path);
        }

        return $missing;
    }

    private function isInside(string $path, string $root): bool
    {
        $root = realpath($root);

        if ($root === false) {
            return false;
        }

        $tail = [];

        while (($real = realpath($path)) === false) {
            $tail[] = basename($path);
            $parent = dirname($path);

            if ($parent === $path) {
                return false;
            }

            $path = $parent;
        }

        $resolved = rtrim($real, '/').($tail === [] ? '' : '/'.implode('/', array_reverse($tail)));

        return $resolved === $root || str_starts_with($resolved, $root.'/');
    }

    /**
     * @param  array<string, string>  $files
     */
    private function write(string $directory, string $manifestBytes, array $files): void
    {
        foreach (['theme.json' => $manifestBytes, ...$files] as $path => $content) {
            $full = "{$directory}/{$path}";

            if ((! is_dir(dirname($full)) && ! @mkdir(dirname($full), 0755, true)) || @file_put_contents($full, $content) !== strlen($content)) {
                $this->reject('pondok.theme_export.write_failed');
            }
        }
    }
}
