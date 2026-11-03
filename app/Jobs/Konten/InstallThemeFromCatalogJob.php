<?php

namespace App\Jobs\Konten;

use App\Actions\Konten\FetchThemeCatalog;
use App\Actions\Konten\InstallPortalTheme;
use App\Actions\Konten\InstallThemeFromCatalog;
use App\Actions\Konten\ThemeCatalogClient;
use App\Actions\Konten\ValidatePortalTheme;
use App\Enums\Module;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonInterface;
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

    public int $timeout = 180;

    /** Tenggat total unduh (detik, jam aplikasi); `$timeout` job di atasnya memberi ruang untuk validasi dan pemasangan. */
    private const DEADLINE_SECONDS = 150;

    private const MAX_FILE_SECONDS = 15;

    private const MAX_THEME_BYTES = ValidatePortalTheme::MAX_BYTES;

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

        $deadline = now()->addSeconds(self::DEADLINE_SECONDS);

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
                $bytes = $client->get($entry['url'], min($entry['size'], self::MAX_THEME_BYTES), $this->timeLeft($deadline));
                $files = $this->downloadAssets($client, $install->declaredAssets($entry, $bytes), $entry['url'], strlen($bytes), $deadline);
            } catch (RuntimeException) {
                $this->mark('failed', __(now()->gte($deadline) ? 'pondok.theme_install.errors.deadline' : 'pondok.theme_install.errors.download_failed'));

                return;
            }

            if (now()->gte($deadline)) {
                $this->mark('failed', __('pondok.theme_install.errors.deadline'));

                return;
            }

            $install->handle($entry, $bytes, User::find($this->userId), $files);

            $this->mark('done');
        } catch (InvalidArgumentException $exception) {
            // Penolakan Action: pesannya selalu dari lang/id.
            $this->mark('failed', $exception->getMessage());
        } catch (Throwable $exception) {
            Log::warning('Pemasangan tema dari katalog gagal.', ['slug' => $this->slug, 'exception' => $exception::class]);

            $this->mark('failed', __('pondok.theme_install.errors.install_failed'));
        }
    }

    /**
     * Unduh tiap berkas aset dari direktori `$manifestUrl` (batas per berkas = min(size terdeklarasi, batas tipe, sisa batas total paket)).
     * Sha256, size, dan isi diperiksa `InstallPortalTheme`; di sini hanya jaringan, batas total, dan tenggat.
     *
     * @param  array<string, int>  $declared  path => size terdeklarasi
     * @return array<string, string>
     *
     * @throws RuntimeException
     */
    private function downloadAssets(ThemeCatalogClient $client, array $declared, string $manifestUrl, int $manifestBytes, CarbonInterface $deadline): array
    {
        $directory = substr($manifestUrl, 0, (int) strrpos($manifestUrl, '/') + 1);
        $total = $manifestBytes;
        $files = [];

        foreach ($declared as $path => $size) {
            $typeLimit = $path === 'theme.css' ? ValidatePortalTheme::MAX_CSS_BYTES : ValidatePortalTheme::MAX_FONT_BYTES;
            $limit = min($size, $typeLimit, ValidatePortalTheme::MAX_PACKAGE_BYTES - $total);

            if ($limit < 1) {
                throw new RuntimeException('Batas total paket tema terlampaui.');
            }

            $files[$path] = $client->get($directory.$path, $limit, $this->timeLeft($deadline));
            $total += strlen($files[$path]);
        }

        return $files;
    }

    /**
     * Sisa tenggat dalam detik, dibatasi per berkas; tenggat yang sudah lewat = gagal.
     *
     * @throws RuntimeException
     */
    private function timeLeft(CarbonInterface $deadline): int
    {
        $left = (int) now()->diffInSeconds($deadline, false);

        if ($left < 1) {
            throw new RuntimeException('Tenggat pemasangan tema terlampaui.');
        }

        return min($left, self::MAX_FILE_SECONDS);
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
