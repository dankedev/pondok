<?php

namespace App\Jobs\Konten;

use App\Actions\Konten\FetchThemeCatalog;
use App\Actions\Konten\InstallPortalTheme;
use App\Actions\Konten\InstallThemeFromCatalog;
use App\Actions\Konten\ThemeCatalogClient;
use App\Enums\Module;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Unduh dan pasang satu tema dari katalog di latar belakang (F103). Katalog dan entri diambil ulang menurut slug (data klien
 * tidak dipercaya); status `themes.install.<slug>` berjalan `running` lalu `done` atau `failed`. Job dengan token basi atau
 * tanpa status berhenti diam-diam, sehingga hanya satu job aktif per slug. Pesan `failed` selalu teks Indonesia generik,
 * tidak pernah isi respons mentah; rincian teknis hanya ke log.
 */
class InstallThemeFromCatalogJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    private const MAX_THEME_BYTES = 65536;

    public function __construct(public readonly string $slug, public readonly string $token, public readonly int $userId) {}

    public function handle(FetchThemeCatalog $catalog, ThemeCatalogClient $client, InstallPortalTheme $install): void
    {
        if (! $this->isCurrent()) {
            return;
        }

        if (! Setting::moduleEnabled(Module::Konten->value)) {
            $this->mark('failed', __('pondok.theme_install.errors.module_disabled'));

            return;
        }

        $this->mark('running');

        try {
            $result = $catalog->handle();

            if ($result['error'] !== null) {
                $this->mark('failed', $result['error']);

                return;
            }

            $entry = collect($result['themes'])->firstWhere('slug', $this->slug);

            if ($entry === null) {
                $this->mark('failed', __('pondok.theme_install.errors.not_in_catalog'));

                return;
            }

            try {
                $bytes = $client->get($entry['url'], min($entry['size'], self::MAX_THEME_BYTES));
            } catch (RuntimeException) {
                $this->mark('failed', __('pondok.theme_install.errors.download_failed'));

                return;
            }

            $install->handle($entry, $bytes, User::find($this->userId));

            $this->mark('done');
        } catch (InvalidArgumentException $exception) {
            // Penolakan Action: pesannya selalu dari lang/id.
            $this->mark('failed', $exception->getMessage());
        } catch (Throwable $exception) {
            Log::warning('Pemasangan tema dari katalog gagal.', ['slug' => $this->slug, 'exception' => $exception::class]);

            $this->mark('failed', __('pondok.theme_install.errors.install_failed'));
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Job pemasangan tema dari katalog gagal.', ['slug' => $this->slug, 'exception' => $exception::class]);

        if ($this->isCurrent()) {
            $this->mark('failed', __('pondok.theme_install.errors.install_failed'));
        }
    }

    private function isCurrent(): bool
    {
        $status = Setting::query()->find(InstallThemeFromCatalog::key($this->slug))?->value;

        return is_array($status) && ($status['token'] ?? null) === $this->token;
    }

    private function mark(string $state, ?string $message = null): void
    {
        Setting::set(InstallThemeFromCatalog::key($this->slug), [
            'state' => $state,
            'token' => $this->token,
            'message' => $message,
            'at' => now()->toIso8601String(),
        ]);
    }
}
