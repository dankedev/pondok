<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ValidatesContentInput;
use App\Models\StaticPage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/**
 * Slug halaman statis (F037). Slug eksplisit di-trim dan di-lowercase lalu harus
 * cocok `StaticPage::SLUG_PATTERN` (maks 50), tidak dicadangkan, dan belum dipakai.
 * Slug kosong dibuat dari judul; slug cadangan diperlakukan seperti sudah dipakai
 * (judul "Berita" → `berita-2`).
 *
 * Slug cadangan: `StaticPage::RESERVED_SLUGS`, segmen pertama setiap URI route
 * terdaftar, dan nama berkas/folder di `public_path()` (disajikan web server
 * sebelum Laravel).
 */
class ResolveStaticPageSlug
{
    use ValidatesContentInput;

    /** @var array<string, true>|null */
    private ?array $routeSegments = null;

    public function __construct(private readonly GenerateSlug $slugs) {}

    public function handle(string $title, mixed $explicit, ?StaticPage $ignore = null): string
    {
        $slug = self::normalize($explicit);

        if ($slug === '') {
            return $this->slugs->handle(StaticPage::class, $title, null, StaticPage::SLUG_MAX_LENGTH, $ignore, isReserved: $this->isReserved(...));
        }

        if (preg_match('/^'.StaticPage::SLUG_PATTERN.'$/', $slug) !== 1) {
            $this->fail('slug', 'slug_format');
        }

        if (strlen($slug) > StaticPage::SLUG_MAX_LENGTH) {
            $this->fail('slug', 'too_long', ['max' => StaticPage::SLUG_MAX_LENGTH]);
        }

        if ($this->isReserved($slug)) {
            $this->fail('slug', 'slug_reserved');
        }

        $taken = StaticPage::query()
            ->where('slug', $slug)
            ->when($ignore?->exists, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($taken) {
            $this->fail('slug', 'slug_taken');
        }

        return $slug;
    }

    public static function normalize(mixed $value): string
    {
        return is_scalar($value) ? mb_strtolower(trim((string) $value)) : '';
    }

    public function isReserved(string $slug): bool
    {
        return in_array($slug, StaticPage::RESERVED_SLUGS, true)
            || isset($this->routeSegments()[$slug])
            || File::exists(public_path($slug));
    }

    /**
     * @return array<string, true>
     */
    private function routeSegments(): array
    {
        if ($this->routeSegments !== null) {
            return $this->routeSegments;
        }

        $segments = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $segment = explode('/', trim($route->uri(), '/'))[0];

            if ($segment !== '' && ! str_starts_with($segment, '{')) {
                $segments[$segment] = true;
            }
        }

        return $this->routeSegments = $segments;
    }
}
