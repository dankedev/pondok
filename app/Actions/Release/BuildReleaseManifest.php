<?php

namespace App\Actions\Release;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Daftar berkas yang masuk paket rilis, dan pemeriksaan kelengkapannya.
 */
class BuildReleaseManifest
{
    /** Folder yang tidak pernah dipaketkan. */
    public const EXCLUDED_PREFIXES = [
        '.git/', '.github/', '.claude/', '.ai/', '.codex/', '.cursor/', '.idea/', '.vscode/', '.zed/', '.nova/',
        '.phpunit.cache/', 'node_modules/', 'tests/', 'docs/plans/', 'docs/handoffs/', 'storage/app/releases/',
        'storage/pail/', 'public/storage/',
    ];

    /** Berkas kerja pengembang. */
    public const EXCLUDED_FILES = [
        '.env', '.phpunit.result.cache', '.phpactor.json', 'phpunit.xml', 'auth.json', 'CLAUDE.md', 'AGENTS.md',
        'boost.json', 'feature_list.json', 'database/database.sqlite', 'public/hot', 'public/fonts-manifest.dev.json',
        '_ide_helper.php',
    ];

    /** Folder runtime: isinya tidak dipaketkan kecuali `.gitignore` (agar kerangka folder ikut). */
    public const EXCLUDED_PATTERNS = [
        'storage/logs/', 'storage/framework/cache/', 'storage/framework/sessions/', 'storage/framework/views/',
        'storage/framework/testing/', 'storage/app/private/', 'storage/app/public/', 'bootstrap/cache/',
    ];

    /** Berkas yang wajib ada di paket. */
    public const REQUIRED_FILES = [
        'artisan', 'composer.json', 'composer.lock', '.env.example', '.env.production.example', '.htaccess', 'VERSION',
        'public/index.php', 'public/.htaccess', 'public/build/manifest.json', 'vendor/autoload.php',
        'storage/framework/views/.gitignore', 'storage/framework/sessions/.gitignore',
        'storage/framework/cache/.gitignore', 'storage/logs/.gitignore', 'bootstrap/cache/.gitignore',
        'docs/instalasi.md',
    ];

    /** Folder wajib yang minimal berisi satu berkas. */
    public const REQUIRED_DIRECTORIES = ['public/js/filament/'];

    /** Bukti `composer install --no-dev` gagal. */
    public const FORBIDDEN_PREFIXES = [
        'vendor/pestphp/', 'vendor/laravel/boost/', 'vendor/mockery/', 'vendor/fakerphp/', 'vendor/laravel/pint/',
    ];

    /**
     * Path relatif (pemisah `/`, urut) tanpa mengikuti symlink.
     *
     * @return list<string>
     */
    public function handle(string $root): array
    {
        $root = rtrim($root, '/');

        $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator($directory, function (SplFileInfo $item) use ($root): bool {
            if ($item->isLink()) {
                return false;
            }

            $relative = $this->relative($root, $item->getPathname());

            return $item->isDir() ? ! $this->directoryExcluded($relative.'/') : true;
        });

        $paths = [];

        foreach (new RecursiveIteratorIterator($filter) as $file) {
            /** @var SplFileInfo $file */
            $relative = $this->relative($root, $file->getPathname());

            if ($file->isFile() && ! $this->excluded($relative)) {
                $paths[] = $relative;
            }
        }

        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * Path wajib yang tidak ada dan path terlarang yang ada, dengan keterangan.
     *
     * @param  list<string>  $manifest
     * @return list<string>
     */
    public function missingRequirements(array $manifest): array
    {
        $present = array_flip($manifest);
        $problems = [];

        foreach (self::REQUIRED_FILES as $path) {
            if (! isset($present[$path])) {
                $problems[] = __('pondok.release.missing_file', ['path' => $path]);
            }
        }

        foreach (self::REQUIRED_DIRECTORIES as $directory) {
            if (! collect($manifest)->contains(fn (string $path): bool => str_starts_with($path, $directory))) {
                $problems[] = __('pondok.release.missing_directory', ['path' => rtrim($directory, '/')]);
            }
        }

        foreach (self::FORBIDDEN_PREFIXES as $prefix) {
            if (collect($manifest)->contains(fn (string $path): bool => str_starts_with($path, $prefix))) {
                $problems[] = __('pondok.release.forbidden_dev_package', ['path' => $prefix]);
            }
        }

        foreach ($manifest as $path) {
            if ($this->isSecretEnv($path)) {
                $problems[] = __('pondok.release.forbidden_env', ['path' => $path]);
            }
        }

        return $problems;
    }

    public function excluded(string $path): bool
    {
        if (in_array($path, self::EXCLUDED_FILES, true) || $this->isSecretEnv($path)) {
            return true;
        }

        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        foreach (self::EXCLUDED_PATTERNS as $directory) {
            if (str_starts_with($path, $directory) && $path !== $directory.'.gitignore') {
                return true;
            }
        }

        return in_array(basename($path), ['.DS_Store', 'Thumbs.db'], true) || str_ends_with($path, '.log');
    }

    /**
     * `.env` atau `.env.*` di folder mana pun, kecuali yang berakhiran `.example`.
     */
    public function isSecretEnv(string $path): bool
    {
        $name = basename($path);

        return ($name === '.env' || str_starts_with($name, '.env.')) && ! str_ends_with($name, '.example');
    }

    private function directoryExcluded(string $directory): bool
    {
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($directory, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function relative(string $root, string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen($root) + 1));
    }
}
