<?php

namespace App\Actions\Konten;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tema portal aktif (F102): pohon `theme.json` tervalidasi untuk slug di `theme.template` (F072), atau null
 * bila portal harus memakai chrome dan komponen bawaan inti.
 *
 * Null untuk: key belum diisi, slug rusak, tema hilang, JSON/skema tak valid, `schema_version` tak didukung,
 * melebihi batas, atau `min_core` di atas inti. Portal tidak pernah gagal karena tema. Tanpa Cache: hasil
 * dimemoisasi per request (binding scoped) dan slug dibaca dari `Setting` yang sudah dimuat F070 (0 query tambahan).
 * Peringatan log ditulis sekali per slug per proses.
 */
class ResolveActiveTheme
{
    /** @var array<string, true> */
    private static array $warned = [];

    private bool $resolved = false;

    /** @var ?array<string, mixed> */
    private ?array $theme = null;

    public function __construct(private readonly ValidatePortalTheme $validate, private readonly ResolveThemePath $paths) {}

    /**
     * @return ?array{slug: string, header: ?array<string, mixed>, footer: ?array<string, mixed>, home: array{layout: list<array<string, mixed>>}}
     */
    public function handle(): ?array
    {
        if (! $this->resolved) {
            $this->theme = $this->resolve();
            $this->resolved = true;
        }

        return $this->theme;
    }

    /**
     * @return ?array<string, mixed>
     */
    private function resolve(): ?array
    {
        $slug = Setting::get('theme.template');

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        // Slug menjadi nama direktori: bentuk yang tak sah tidak pernah menyentuh filesystem.
        if (! ResolveThemePath::isSlug($slug)) {
            $this->warn($slug, 'invalid slug');

            return null;
        }

        try {
            $path = $this->paths->handle($slug);

            if ($path === null) {
                $this->warn($slug, 'theme file missing');

                return null;
            }

            return $this->validate->handle($path);
        } catch (Throwable $e) {
            $this->warn($slug, $e->getMessage());

            return null;
        }
    }

    private function warn(string $slug, string $reason): void
    {
        if (isset(self::$warned[$slug])) {
            return;
        }

        self::$warned[$slug] = true;

        Log::warning('Portal theme is unusable; falling back to the core chrome.', ['slug' => Str::limit($slug, 60), 'reason' => $reason]);
    }
}
