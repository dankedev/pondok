<?php

namespace App\Actions\Konten\Concerns;

use App\Actions\Konten\ResolveThemePath;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Bersama Action tulis tema terpasang (F103): pasang, kembalikan, hapus. Slug tak tepercaya divalidasi sebelum path apa pun
 * dibentuk, penulisan satu slug diserialkan dengan `Cache::lock`, dan berkas baru selalu lewat `_staging/<slug>` dulu.
 */
trait WritesInstalledThemes
{
    protected function authorizeManagement(?User $actor): void
    {
        if ($actor === null || ! $actor->can('manage:themes')) {
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
     * @param  array<string, mixed>  $replace
     */
    protected function reject(string $reason, array $replace = []): never
    {
        throw new InvalidArgumentException(__("pondok.theme_install.errors.{$reason}", $replace));
    }
}
