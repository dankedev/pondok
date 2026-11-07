<?php

namespace App\Actions\Konten\Concerns;

use App\Actions\Konten\ResolveThemePath;
use App\Actions\Konten\ValidateThemePackage;
use App\Enums\Module;
use App\Models\Setting;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Bersama Action tulis tema terpasang (F103): pasang, kembalikan, hapus. Slug tak tepercaya divalidasi sebelum path apa pun
 * dibentuk, penulisan satu slug diserialkan dengan `Cache::lock`, dan berkas baru selalu lewat `_staging/<slug>` dulu.
 */
trait WritesInstalledThemes
{
    protected function authorizeManagement(?User $actor): void
    {
        if ($actor === null || ! Setting::moduleEnabled(Module::Konten->value) || ! $actor->can('manage:themes')) {
            throw new AuthorizationException(__('pondok.theme_install.errors.forbidden'));
        }
    }

    /**
     * Slug harus berbentuk sah dan bukan tema resmi; mengembalikan slug itu.
     */
    protected function assertThemeSlug(mixed $slug): string
    {
        if (! ResolveThemePath::isSlug($slug)) {
            $this->reject('invalid_slug');
        }

        if (ResolveThemePath::isOfficial($slug)) {
            $this->reject('official_slug');
        }

        return $slug;
    }

    protected function themeRoot(): string
    {
        return rtrim((string) config('pondok.installed_themes_path'), '/');
    }

    protected function stagingDirectory(string $slug): string
    {
        return $this->themeRoot()."/_staging/{$slug}";
    }

    /**
     * Jalankan `$callback` dengan kunci tulis slug; kunci yang sedang dipegang menolak dengan pesan jelas.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function withWriteLock(string $slug, Closure $callback): mixed
    {
        $lock = Cache::lock("themes.write.{$slug}", 10);

        if (! $lock->get()) {
            $this->reject('locked');
        }

        try {
            return $callback();
        } finally {
            File::deleteDirectory($this->stagingDirectory($slug));
            $lock->release();
        }
    }

    /**
     * Siapkan folder staging kosong untuk `$slug` berisi `theme.json` = `$bytes`; mengembalikan path berkas itu.
     */
    protected function stage(string $slug, string $bytes): string
    {
        $directory = $this->stagingDirectory($slug);

        File::deleteDirectory($directory);

        if (! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->reject('write_failed');
        }

        if (@file_put_contents("{$directory}/theme.json", $bytes) !== strlen($bytes)) {
            $this->reject('write_failed');
        }

        return "{$directory}/theme.json";
    }

    /**
     * 12 hex pertama sha256 isi berkas manifest: nama folder aset (`assets/<hash12>`) milik manifest itu (F105); null bila berkas tak ada.
     */
    protected function assetHash(string $manifestPath): ?string
    {
        return is_file($manifestPath) ? substr((string) hash_file('sha256', $manifestPath), 0, 12) : null;
    }

    /**
     * Pangkas `<slug>/assets/*` selain folder milik `theme.json` dan `previous.json`; `assets` yang kosong ikut dihapus.
     * Bertahan-terbaik: kegagalan menghapus tidak mengubah invarian (folder aset milik `theme.json` aktif selalu lengkap).
     */
    protected function pruneAssets(string $slug): void
    {
        $directory = $this->themeRoot()."/{$slug}";
        $assets = "{$directory}/assets";

        if (! is_dir($assets) || is_link($assets)) {
            return;
        }

        $keep = array_filter([$this->assetHash("{$directory}/theme.json"), $this->assetHash("{$directory}/previous.json")]);

        foreach (array_diff(scandir($assets) ?: [], ['.', '..', ...$keep]) as $entry) {
            is_dir("{$assets}/{$entry}") && ! is_link("{$assets}/{$entry}") ? File::deleteDirectory("{$assets}/{$entry}") : @unlink("{$assets}/{$entry}");
        }

        @rmdir($assets);
    }

    /**
     * Tulis berkas aset ke staging (hanya path yang dideklarasikan manifest) lalu periksa paketnya; tema tanpa `assets` tidak boleh membawa berkas.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $files
     */
    protected function stageAssets(ValidateThemePackage $package, string $slug, array $manifest, array $files): void
    {
        $declared = ValidateThemePackage::paths($manifest);

        foreach (array_diff(array_keys($files), $declared) as $path) {
            $this->reject('pondok.theme_assets.file_extra', ['path' => Str::limit((string) $path, 60)]);
        }

        foreach (array_diff($declared, array_keys($files)) as $path) {
            $this->reject('pondok.theme_assets.file_missing', ['path' => $path]);
        }

        if (! isset($manifest['assets'])) {
            return;
        }

        $directory = $this->stagingDirectory($slug);

        foreach ($files as $path => $content) {
            if (! is_string($content) || (! is_dir(dirname("{$directory}/{$path}")) && ! @mkdir(dirname("{$directory}/{$path}"), 0755, true))
                || @file_put_contents("{$directory}/{$path}", $content) !== strlen($content)) {
                $this->reject('write_failed');
            }
        }

        $package->handle($directory, $manifest);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    protected function swap(string $slug, array $manifest): void
    {
        $directory = $this->themeRoot()."/{$slug}";
        $theme = "{$directory}/theme.json";
        $temporary = "{$directory}/previous.json.tmp";
        $staging = $this->stagingDirectory($slug);
        $hasOld = is_file($theme);
        $target = isset($manifest['assets']) ? "{$directory}/assets/".$this->assetHash("{$staging}/theme.json") : null;
        $created = false;
        $swapped = false;

        if (is_link($directory)) {
            $this->reject('write_failed');
        }

        if (! $hasOld && ! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->reject('write_failed');
        }

        try {
            if ($target !== null) {
                $created = $this->placeAssets($staging, $target, [$this->assetHash($theme), $this->assetHash("{$directory}/previous.json")]);
            }

            if ($hasOld && ! @copy($theme, $temporary)) {
                $this->reject('write_failed');
            }

            if (! @rename("{$staging}/theme.json", $theme)) {
                $this->reject('write_failed');
            }

            $swapped = true;

            if ($hasOld && ! @rename($temporary, "{$directory}/previous.json")) {
                $this->reject('write_failed');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }

            // Gagal sebelum `theme.json` berganti: folder aset baru tak dimiliki siapa pun, buang.
            if ($created && ! $swapped) {
                File::deleteDirectory((string) $target);
            }
        }

        $this->pruneAssets($slug);
    }

    /**
     * Pindahkan `theme.css` dan `fonts/` dari staging ke `$target`. Folder `$target` yang sudah ada dan milik `theme.json`/`previous.json`
     * aktif (hash sama) sudah lengkap dan dibiarkan; yang bukan milik siapa pun (sisa pemasangan gagal) dihapus lalu diganti.
     *
     * @param  list<?string>  $owned
     * @return bool true bila folder baru dibuat
     */
    protected function placeAssets(string $staging, string $target, array $owned): bool
    {
        if (is_dir($target) && in_array(basename($target), $owned, true)) {
            return false;
        }

        if (is_link($target) || (file_exists($target) && ! is_dir($target))) {
            @unlink($target);
        }

        File::deleteDirectory($target);

        if (! is_dir(dirname($target)) && ! @mkdir(dirname($target), 0755, true) && ! is_dir(dirname($target))) {
            $this->reject('write_failed');
        }

        if (! @mkdir($target, 0755)) {
            $this->reject('write_failed');
        }

        foreach (['theme.css', 'fonts'] as $name) {
            if (file_exists("{$staging}/{$name}") && ! @rename("{$staging}/{$name}", "{$target}/{$name}")) {
                File::deleteDirectory($target);
                $this->reject('write_failed');
            }
        }

        return true;
    }

    /**
     * Isi berkas aset (path => byte) milik `theme.json` terpasang di `$directory`; ditolak bila ada yang hilang atau bukan berkas biasa.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, string>
     */
    protected function installedAssetFiles(string $directory, array $manifest): array
    {
        $files = [];
        $source = "{$directory}/assets/".$this->assetHash("{$directory}/theme.json");

        foreach (ValidateThemePackage::paths($manifest) as $path) {
            $bytes = is_file("{$source}/{$path}") && ! is_link("{$source}/{$path}") ? @file_get_contents("{$source}/{$path}") : false;

            if ($bytes === false) {
                $this->reject('write_failed');
            }

            $files[$path] = $bytes;
        }

        return $files;
    }

    /**
     * Selektor scope CSS tema (F105).
     */
    protected function scope(string $slug): string
    {
        return 'body[data-pt-theme="'.$slug.'"]';
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    protected function encodeManifest(array $manifest): string
    {
        return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /**
     * @param  string  $reason  kunci di `pondok.theme_install.errors`, atau kunci lang penuh (mengandung titik)
     * @param  array<string, mixed>  $replace
     */
    protected function reject(string $reason, array $replace = []): never
    {
        throw new InvalidArgumentException(__(str_contains($reason, '.') ? $reason : "pondok.theme_install.errors.{$reason}", $replace));
    }
}
