<?php

namespace App\Console\Commands;

use App\Actions\Release\BuildReleaseManifest;
use App\Actions\Release\PrepareReleaseStaging;
use App\Actions\Release\WriteReleaseArchive;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Number;
use RuntimeException;

/**
 * Membuat paket rilis zip untuk shared hosting dari commit `HEAD`. Dijalankan
 * pengelola di mesin build (butuh git, Composer, pnpm), ditolak di production.
 */
#[Signature('pondok:release
    {--release-version= : Versi paket, bawaan dari git describe}
    {--output= : Folder hasil, bawaan storage/app/releases}
    {--allow-dirty : Tetap lanjut walau ada perubahan belum di-commit}
    {--keep-staging : Jangan hapus folder staging}')]
#[Description('Membuat paket rilis zip untuk cPanel')]
class ReleaseCommand extends Command
{
    private const VERSION_PATTERN = '/^[0-9A-Za-z][0-9A-Za-z.+-]*$/';

    public function handle(PrepareReleaseStaging $prepare, BuildReleaseManifest $manifest, WriteReleaseArchive $archive): int
    {
        if (app()->environment('production')) {
            $this->error(__('pondok.release.production_refused'));

            return self::FAILURE;
        }

        $requested = $this->option('release-version');

        if (filled($requested) && ! $this->validVersion((string) $requested)) {
            return self::FAILURE;
        }

        if (! $this->workingTreeAccepted()) {
            return self::FAILURE;
        }

        $version = filled($requested) ? (string) $requested : $this->describeVersion();

        if ($version === null || ! $this->validVersion($version)) {
            return self::FAILURE;
        }

        $output = rtrim((string) ($this->option('output') ?: storage_path('app/releases')), '/');
        $staging = sys_get_temp_dir().'/pondok-release-'.bin2hex(random_bytes(6));

        try {
            $this->components->task(__('pondok.release.step_staging'), fn () => $prepare->handle(base_path(), $staging, $version));

            $files = $manifest->handle($staging);
            $problems = $manifest->missingRequirements($files);

            if ($problems !== []) {
                $this->error(__('pondok.release.incomplete'));
                $this->bullets($problems);

                return self::FAILURE;
            }

            File::ensureDirectoryExists($output);
            $result = $archive->handle($staging, $files, $output.'/pondok-'.$version.'.zip');
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if (! $this->option('keep-staging')) {
                File::deleteDirectory($staging);
            } else {
                $this->info(__('pondok.release.staging_kept', ['path' => $staging]));
            }
        }

        $this->info(__('pondok.release.done'));
        $this->line(__('pondok.release.file').': '.$result['path']);
        $this->line(__('pondok.release.files_count').': '.(string) $result['files']);
        $this->line(__('pondok.release.size').': '.Number::fileSize((int) filesize($result['path']), 1));
        $this->line('SHA-256: '.$result['sha256']);

        return self::SUCCESS;
    }

    private function validVersion(string $version): bool
    {
        if (preg_match(self::VERSION_PATTERN, $version) === 1) {
            return true;
        }

        $this->error(__('pondok.release.invalid_version', ['version' => $version]));

        return false;
    }

    /**
     * Hanya commit `HEAD` yang dipaketkan, jadi perubahan yang belum di-commit
     * tidak ikut. Ditolak kecuali `--allow-dirty`.
     */
    private function workingTreeAccepted(): bool
    {
        $status = Process::path(base_path())->run(['git', 'status', '--porcelain']);

        if ($status->failed()) {
            $this->error(__('pondok.release.git_failed', ['error' => trim($status->errorOutput())]));

            return false;
        }

        if (trim($status->output()) === '') {
            return true;
        }

        if (! $this->option('allow-dirty')) {
            $this->error(__('pondok.release.dirty_tree'));

            return false;
        }

        $this->warn(__('pondok.release.dirty_allowed'));

        return true;
    }

    private function describeVersion(): ?string
    {
        $describe = Process::path(base_path())->run(['git', 'describe', '--tags', '--always']);

        if ($describe->failed() || trim($describe->output()) === '') {
            $this->error(__('pondok.release.git_failed', ['error' => trim($describe->errorOutput())]));

            return null;
        }

        return trim($describe->output());
    }

    /**
     * @param  list<string>  $items
     */
    private function bullets(array $items): void
    {
        foreach ($items as $item) {
            $this->line('  - '.$item);
        }
    }
}
