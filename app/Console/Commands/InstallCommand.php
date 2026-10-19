<?php

namespace App\Console\Commands;

use App\Actions\Core\CheckSystemHealth;
use App\Actions\Core\CreateSuperAdmin;
use App\Actions\Core\LinkPublicStorage;
use App\Actions\Core\PreserveCpanelHtaccessBlocks;
use App\Actions\Core\PublishPublicDirectory;
use App\Models\User;
use Database\Seeders\InstallSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Instalasi di shared hosting dari Terminal atau cron sekali jalan. Aman
 * dijalankan ulang: seeder memakai firstOrCreate dan super-admin tidak diubah.
 */
#[Signature('pondok:install
    {--admin-name= : Nama super-admin}
    {--admin-email= : Email super-admin}
    {--admin-password= : Password super-admin (minimal 8 karakter)}
    {--skip-if-installed : Berhenti tanpa mengubah apa pun bila super-admin sudah ada (entrypoint Docker)}')]
#[Description('Memasang Pondok: cek server, APP_KEY, migrasi, data awal, super-admin, dan storage')]
class InstallCommand extends Command
{
    public function handle(
        CheckSystemHealth $health,
        CreateSuperAdmin $createSuperAdmin,
        LinkPublicStorage $linkStorage,
        PreserveCpanelHtaccessBlocks $htaccess,
    ): int {
        if (! $this->requirementsMet($health)) {
            return self::FAILURE;
        }

        if (! $this->environmentFileExists()) {
            return self::FAILURE;
        }

        // Instalasi yang sudah berjalan tidak di-seed ulang: data bawaan yang dihapus admin tidak muncul lagi (F064).
        if ($this->option('skip-if-installed') && $this->superAdminExists()) {
            $this->info(__('pondok.install.already_installed'));

            return self::SUCCESS;
        }

        $credentials = $this->credentials();

        if ($credentials === false) {
            return self::FAILURE;
        }

        if ($credentials !== null) {
            try {
                $createSuperAdmin->validate(...$credentials);
            } catch (ValidationException $exception) {
                $this->validationErrors($exception);

                return self::FAILURE;
            }
        }

        if (blank(config('app.key')) && ! $this->generateKey()) {
            return self::FAILURE;
        }

        $database = $health->only(['database'])[0];

        if ($database['status'] === 'failure') {
            $this->error($database['message']);
            $this->error(__('pondok.install.database_hint'));

            return self::FAILURE;
        }

        try {
            $this->components->task(__('pondok.install.step_migrate'), fn () => $this->artisanStep('migrate', ['--force' => true]));
            $this->components->task(__('pondok.install.step_seed'), fn () => $this->artisanStep('db:seed', ['--class' => InstallSeeder::class, '--force' => true]));

            if ($credentials !== null) {
                $result = $createSuperAdmin->handle(...$credentials);

                $result['created']
                    ? $this->info(__('pondok.install.super_admin_created', ['email' => $result['user']->email]))
                    : $this->warn(__('pondok.install.super_admin_exists', ['email' => $result['user']->email]));
            } else {
                $this->info(__('pondok.install.super_admin_skipped'));
            }

            $this->publishPublicDirectory();
            $htaccess->remember(public_path('.htaccess'));
            $this->linkStorage($linkStorage);

            $this->callSilently('optimize:clear');
        } catch (Throwable $exception) {
            $this->error(__('pondok.install.failed', ['error' => $exception->getMessage()]));

            return self::FAILURE;
        }

        $this->summary();

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function artisanStep(string $command, array $arguments): void
    {
        if ($this->callSilently($command, $arguments) !== self::SUCCESS) {
            throw new RuntimeException(__('pondok.install.command_failed', ['command' => $command]));
        }
    }

    private function requirementsMet(CheckSystemHealth $health): bool
    {
        $failures = collect($health->only(['php_version', 'extensions', 'writable_paths']))->where('status', 'failure');

        foreach ($failures as $failure) {
            $this->error($failure['message']);
        }

        return $failures->isEmpty();
    }

    /**
     * `.env` belum ada: salin templat produksi lalu minta operator mengisinya.
     */
    private function environmentFileExists(): bool
    {
        $path = app()->environmentFilePath();

        if (is_file($path)) {
            return true;
        }

        copy(base_path('.env.production.example'), $path);

        $this->warn(__('pondok.install.env_created', ['path' => $path]));
        $this->bullets([
            __('pondok.install.env_fill_database'),
            __('pondok.install.env_fill_url'),
            __('pondok.install.env_fill_admin'),
        ]);
        $this->info(__('pondok.install.env_rerun'));

        return false;
    }

    /**
     * Opsi > env (`SUPER_ADMIN_*`) > pertanyaan interaktif. Null bila super-admin
     * sudah ada dan tidak ada kredensial; false bila gagal.
     *
     * @return array{0: string, 1: string, 2: string}|null|false
     */
    private function credentials(): array|null|false
    {
        $config = (array) config('pondok.super_admin');

        if (filled($this->option('admin-email')) || filled($this->option('admin-password'))) {
            return [
                (string) ($this->option('admin-name') ?: ($config['name'] ?? 'Super Admin')),
                (string) $this->option('admin-email'),
                (string) $this->option('admin-password'),
            ];
        }

        if (filled($config['email'] ?? null) && filled($config['password'] ?? null)) {
            return [(string) ($config['name'] ?? 'Super Admin'), (string) $config['email'], (string) $config['password']];
        }

        if ($this->superAdminExists()) {
            return null;
        }

        if (! $this->input->isInteractive()) {
            $this->error(__('pondok.install.credentials_required'));

            return false;
        }

        $name = (string) $this->ask(__('pondok.install.ask_admin_name'), $config['name'] ?? null);
        $email = (string) $this->ask(__('pondok.install.ask_admin_email'));
        $password = (string) $this->secret(__('pondok.install.ask_admin_password'));
        $confirmation = (string) $this->secret(__('pondok.install.ask_admin_password_confirmation'));

        if ($password !== $confirmation) {
            $this->error(__('pondok.install.password_mismatch'));

            return false;
        }

        return [$name, $email, $password];
    }

    private function superAdminExists(): bool
    {
        try {
            return Schema::hasTable('roles')
                && User::role(RolePermissionSeeder::SUPER_ADMIN)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function generateKey(): bool
    {
        $this->callSilently('key:generate', ['--force' => true]);

        if (blank(config('app.key'))) {
            $this->error(__('pondok.install.key_failed', ['path' => app()->environmentFilePath()]));

            return false;
        }

        $this->info(__('pondok.install.key_generated'));

        return true;
    }

    private function publishPublicDirectory(): void
    {
        $target = rtrim((string) config('pondok.public_path'), '/');

        if ($target === '') {
            return;
        }

        $result = app(PublishPublicDirectory::class)->handle(base_path('public'), $target, base_path());

        $this->info(__('pondok.publish_public.done', ['count' => $result['copied'], 'path' => $target]));
    }

    private function linkStorage(LinkPublicStorage $linkStorage): void
    {
        $result = $linkStorage->handle();

        match ($result['status']) {
            'linked', 'already_linked', 'direct' => $this->info(__("pondok.install.storage.{$result['status']}", ['link' => $result['link']])),
            'conflict' => $this->warn(__('pondok.install.storage.conflict', ['link' => $result['link']])),
            default => $this->warn(__("pondok.install.storage.{$result['status']}")),
        };

        if ($result['suggestion'] !== null) {
            $this->line('  '.$result['suggestion']);
        }
    }

    private function validationErrors(ValidationException $exception): void
    {
        foreach ($exception->validator->errors()->all() as $message) {
            $this->error($message);
        }
    }

    private function summary(): void
    {
        $this->newLine();
        $this->info(__('pondok.install.done'));
        $this->line(__('pondok.install.login_url').': '.rtrim((string) config('app.url'), '/').'/admin');
        $this->line('  '.__('pondok.install.cron_hint'));
        $this->line('  * * * * * '.PHP_BINARY.' '.base_path('artisan').' schedule:run >> /dev/null 2>&1');
        $this->newLine();
        $this->bullets([
            __('pondok.install.remove_password_hint'),
            __('pondok.install.doctor_hint'),
        ]);
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
