<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\WritesInstalledThemes;
use App\Jobs\Konten\InstallThemeFromCatalogJob;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Minta pemasangan tema dari katalog (F103, hanya pemegang `manage:themes`, modul Konten aktif): menulis status
 * `themes.install.<slug>` = `{state: queued, token, message, at}` dan mendorong tepat satu `InstallThemeFromCatalogJob`.
 * Status `queued|running` yang masih segar (<= 5 menit) tidak berbuat apa-apa, jadi klik ganda tidak membuat job kedua;
 * yang lebih lama dihitung `failed` (job macet) dan boleh diulang. Klaim diserialkan dengan `Cache::lock` singkat.
 */
class InstallThemeFromCatalog
{
    use WritesInstalledThemes;

    public const STALE_MINUTES = 5;

    /**
     * @throws InvalidArgumentException
     */
    public function handle(string $slug, User $actor): void
    {
        $this->authorizeManagement($actor);
        $slug = $this->assertThemeSlug($slug);

        $lock = Cache::lock("themes.install.{$slug}", 5);

        if (! $lock->get()) {
            return;
        }

        try {
            if (in_array(self::status($slug)['state'] ?? null, ['queued', 'running'], true)) {
                return;
            }

            $token = Str::random(32);

            Setting::set(self::key($slug), ['state' => 'queued', 'token' => $token, 'message' => null, 'at' => now()->toIso8601String()]);

            InstallThemeFromCatalogJob::dispatch($slug, $token, $actor->id);
        } finally {
            $lock->release();
        }
    }

    public static function key(string $slug): string
    {
        return "themes.install.{$slug}";
    }

    /**
     * Status pasang tema; `queued|running` yang lebih tua dari 5 menit dibaca sebagai `failed`. Null bila belum pernah dipasang.
     *
     * @return array{state: string, token: string, message: ?string, at: string}|null
     */
    public static function status(string $slug): ?array
    {
        $status = Setting::get(self::key($slug));

        if (! is_array($status) || ! is_string($status['state'] ?? null)) {
            return null;
        }

        $at = is_string($status['at'] ?? null) ? now()->parse($status['at']) : null;

        if (in_array($status['state'], ['queued', 'running'], true) && ($at === null || $at->lt(now()->subMinutes(self::STALE_MINUTES)))) {
            return [...$status, 'state' => 'failed', 'message' => __('pondok.theme_install.errors.stalled')];
        }

        return $status;
    }
}
