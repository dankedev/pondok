<?php

namespace App\Actions\Core;

use FilesystemIterator;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Menyalin isi folder `public/` aplikasi ke document root lain (mis.
 * `public_html`) bila document root domain tidak bisa diubah, lalu menulis
 * `index.php` yang menunjuk ke folder aplikasi.
 */
class PublishPublicDirectory
{
    public const MARKER = 'pondok:public-index';

    /** Tidak disalin: ditulis khusus (index.php, .htaccess) atau milik runtime. */
    private const SKIPPED = ['index.php', '.htaccess', 'storage', 'hot'];

    /** Folder milik Pondok di target yang dihapus dulu agar aset lama berhash tidak menumpuk. */
    private const OWNED_DIRECTORIES = ['build', 'css/filament', 'js/filament', 'fonts/filament'];

    public function __construct(private PreserveCpanelHtaccessBlocks $htaccess) {}

    /**
     * @return array{copied: int}
     */
    public function handle(string $source, string $target, string $basePath, bool $force = false): array
    {
        $source = rtrim($source, '/');
        $target = rtrim($target, '/');
        $basePath = rtrim($basePath, '/');

        $this->guard($source, $target, $basePath, $force);

        foreach (self::OWNED_DIRECTORIES as $directory) {
            $this->removeOwnedDirectory($target, $directory);
        }

        $copied = 0;

        foreach (new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS) as $entry) {
            /** @var SplFileInfo $entry */
            if (in_array($entry->getFilename(), self::SKIPPED, true)) {
                continue;
            }

            $copied += $this->copy($entry->getPathname(), $target.'/'.$entry->getFilename());
        }

        if (is_file($source.'/.htaccess')) {
            $existing = is_file($target.'/.htaccess') ? (string) file_get_contents($target.'/.htaccess') : '';

            file_put_contents($target.'/.htaccess', $this->htaccess->merge(
                (string) file_get_contents($source.'/.htaccess'),
                $this->htaccess->extract($existing),
            ));
            $copied++;
        }

        file_put_contents($target.'/index.php', str_replace(
            '{{ basePath }}',
            var_export($basePath, true),
            (string) file_get_contents(resource_path('stubs/public-index.php.stub')),
        ));

        return ['copied' => $copied];
    }

    /**
     * Hapus folder milik aplikasi tanpa pernah menelusuri symlink: symlink dihapus
     * linknya saja, dan folder yang induknya berada di luar target dilewati.
     */
    private function removeOwnedDirectory(string $target, string $directory): void
    {
        $path = $target.'/'.$directory;

        if (is_link($path)) {
            unlink($path);

            return;
        }

        $realTarget = realpath($target);
        $realParent = realpath(dirname($path));

        if ($realTarget === false || $realParent === false || ! str_starts_with($realParent.'/', $realTarget.'/')) {
            return;
        }

        File::deleteDirectory($path);
    }

    /**
     * Semua pemeriksaan dilakukan sebelum menghapus atau menulis apa pun.
     */
    private function guard(string $source, string $target, string $basePath, bool $force): void
    {
        if (! is_file($source.'/build/manifest.json')) {
            throw new RuntimeException(__('pondok.publish_public.errors.no_build', ['path' => $source.'/build/manifest.json']));
        }

        if (! is_dir($target) || ! is_writable($target)) {
            throw new RuntimeException(__('pondok.publish_public.errors.target_missing', ['path' => $target]));
        }

        $realTarget = (string) realpath($target);
        $realSource = (string) realpath($source);
        $realBase = rtrim(realpath($basePath) ?: $basePath, '/');

        if ($realTarget === $realSource) {
            throw new RuntimeException(__('pondok.publish_public.errors.target_is_source', ['path' => $target]));
        }

        if ($realTarget === $realBase || str_starts_with($realBase.'/', rtrim($realTarget, '/').'/')) {
            throw new RuntimeException(__('pondok.publish_public.errors.target_is_app', ['path' => $target]));
        }

        $index = $target.'/index.php';

        if (! $force && is_file($index) && ! str_contains((string) file_get_contents($index), self::MARKER)) {
            throw new RuntimeException(__('pondok.publish_public.errors.foreign_index', ['path' => $index]));
        }
    }

    /**
     * Menyalin berkas atau folder (rekursif, termasuk dotfile). Mengembalikan jumlah berkas.
     */
    private function copy(string $from, string $to): int
    {
        if (is_link($from)) {
            return 0;
        }

        if (is_file($from)) {
            File::ensureDirectoryExists(dirname($to));
            File::copy($from, $to);

            return 1;
        }

        $count = 0;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        File::ensureDirectoryExists($to);

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $destination = $to.'/'.substr($item->getPathname(), strlen($from) + 1);

            if ($item->isLink()) {
                continue;
            }

            if ($item->isDir()) {
                File::ensureDirectoryExists($destination);

                continue;
            }

            File::ensureDirectoryExists(dirname($destination));
            File::copy($item->getPathname(), $destination);
            $count++;
        }

        return $count;
    }
}
