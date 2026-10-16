<?php

namespace App\Console\Commands;

use App\Actions\Core\CheckSystemHealth;
use App\Actions\Core\PreserveCpanelHtaccessBlocks;
use App\Actions\Core\PublishPublicDirectory;
use Database\Seeders\SystemSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Pembaruan setelah kode versi baru diekstrak: mode pemeliharaan, migrasi,
 * data sistem baru (permission, setting), blok `.htaccess` cPanel, cache.
 * Bila langkah setelah `down` gagal, aplikasi sengaja tetap dalam mode
 * pemeliharaan agar pengunjung tidak melihat situs setengah diperbarui.
 */
#[Signature('pondok:upgrade {--force : Lewati konfirmasi backup}')]
#[Description('Memperbarui Pondok setelah versi baru diekstrak')]
class UpgradeCommand extends Command
{
    public function handle(CheckSystemHealth $health, PreserveCpanelHtaccessBlocks $htaccess): int
    {
        if (! $this->backupConfirmed()) {
            return self::FAILURE;
        }

        $failures = collect($health->only(['php_version', 'extensions', 'app_key', 'database', 'writable_paths']))->where('status', 'failure');

        if ($failures->isNotEmpty()) {
            foreach ($failures as $failure) {
                $this->error($failure['message']);
            }

            return self::FAILURE;
        }

        $wasDown = app()->isDownForMaintenance();

        if (! $wasDown) {
            $this->callSilently('down', ['--retry' => 60]);
            $this->info(__('pondok.upgrade.maintenance_on'));
        }

        try {
            $this->components->task(__('pondok.upgrade.step_migrate'), fn () => $this->artisanStep('migrate', ['--force' => true]));
        } catch (Throwable $exception) {
            $this->error(__('pondok.upgrade.migrate_failed', ['error' => $exception->getMessage()]));
            $this->error(__('pondok.upgrade.migrate_recover'));

            return self::FAILURE;
        }

        try {
            $this->components->task(__('pondok.upgrade.step_seed'), fn () => $this->artisanStep('db:seed', ['--class' => SystemSeeder::class, '--force' => true]));

            $htaccess->restore(public_path('.htaccess'));
            $htaccess->remember(public_path('.htaccess'));
            $this->publishPublicDirectory();

            $this->components->task(__('pondok.upgrade.step_clear'), fn () => $this->artisanStep('optimize:clear', []));
        } catch (Throwable $exception) {
            $this->error(__('pondok.upgrade.step_failed', ['error' => $exception->getMessage()]));
            $this->error(__('pondok.upgrade.step_recover'));

            return self::FAILURE;
        }

        if (! $wasDown) {
            $this->callSilently('up');
        }

        $this->info($wasDown ? __('pondok.upgrade.done_still_down') : __('pondok.upgrade.done'));
        $this->bullets([__('pondok.upgrade.doctor_hint')]);

        return self::SUCCESS;
    }

    private function backupConfirmed(): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error(__('pondok.upgrade.force_required'));

            return false;
        }

        if (! $this->confirm(__('pondok.upgrade.confirm_backup'))) {
            $this->warn(__('pondok.upgrade.cancelled'));

            return false;
        }

        return true;
    }

    private function publishPublicDirectory(): void
    {
        $target = rtrim((string) config('pondok.public_path'), '/');

        if ($target === '') {
            return;
        }

        $result = app(PublishPublicDirectory::class)->handle(base_path('public'), $target, base_path(), false);

        $this->info(__('pondok.publish_public.done', ['count' => $result['copied'], 'path' => $target]));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function artisanStep(string $command, array $arguments): void
    {
        if ($this->callSilently($command, $arguments) !== self::SUCCESS) {
            throw new RuntimeException(__('pondok.upgrade.command_failed', ['command' => $command]));
        }
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
