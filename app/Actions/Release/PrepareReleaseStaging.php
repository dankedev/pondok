<?php

namespace App\Actions\Release;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use ZipArchive;

/**
 * Menyiapkan folder staging rilis dari commit `HEAD`: ekspor git, dependency
 * PHP tanpa paket dev, build aset Vite (pnpm), lalu berkas VERSION.
 */
class PrepareReleaseStaging
{
    public const TIMEOUT = 900;

    public function handle(string $repository, string $staging, string $version): void
    {
        $staging = rtrim($staging, '/');
        $sourceZip = $staging.'.src.zip';

        try {
            $this->run($repository, ['git', 'archive', '--format=zip', '--output='.$sourceZip, 'HEAD']);
            $this->extract($sourceZip, $staging);
        } finally {
            if (is_file($sourceZip)) {
                unlink($sourceZip);
            }
        }

        // Skrip composer tetap jalan agar `filament:upgrade` menerbitkan aset Filament.
        $this->run($staging, ['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--prefer-dist', '--no-progress']);
        $this->run($staging, ['pnpm', 'install', '--frozen-lockfile', '--ignore-workspace']);
        $this->run($staging, ['pnpm', 'run', 'build']);

        file_put_contents($staging.'/VERSION', $version."\n");
    }

    /**
     * @param  list<string>  $command
     */
    private function run(string $path, array $command): void
    {
        $result = Process::path($path)->timeout(self::TIMEOUT)->run($command);

        if ($result->failed()) {
            throw new RuntimeException(__('pondok.release.step_failed', [
                'command' => implode(' ', $command),
                'error' => trim($result->errorOutput() ?: $result->output()),
            ]));
        }
    }

    private function extract(string $zipPath, string $destination): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException(__('pondok.release.zip_failed', ['path' => $zipPath]));
        }

        File::ensureDirectoryExists($destination);
        $zip->extractTo($destination);
        $zip->close();
    }
}
