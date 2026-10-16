<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ValidatesContentInput;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Slug unik untuk post, album, dan kategori.
 *
 * Slug eksplisit dinormalisasi `Str::slug` dan ditolak bila kosong, terlalu panjang,
 * atau sudah dipakai (tanpa akhiran otomatis). Tanpa slug eksplisit, slug dibuat dari
 * sumber (judul/nama; aksara Arab ditransliterasi) dengan akhiran `-2`, `-3`, … bila
 * sudah dipakai; sumber tanpa huruf/angka memakai nama model (`post`, `album`, `category`).
 * Model soft delete ikut memeriksa baris terhapus. `$isReserved` (opsional) menandai slug
 * cadangan yang diperlakukan seperti sudah dipakai saat slug dibuat otomatis.
 * Unique index DB tetap pengaman terakhir.
 */
class GenerateSlug
{
    use ValidatesContentInput;

    /** Ruang yang disisakan untuk akhiran `-N` pada slug otomatis. */
    private const SUFFIX_RESERVE = 10;

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $scope  batas keunikan, mis. `['type' => 'album']`
     * @param  (Closure(string): bool)|null  $isReserved
     */
    public function handle(string $modelClass, string $source, ?string $explicit, int $maxLength, ?Model $ignore = null, array $scope = [], ?Closure $isReserved = null): string
    {
        if (filled($explicit)) {
            $slug = Str::slug((string) $explicit);

            if ($slug === '') {
                $this->fail('slug', 'slug_invalid');
            }

            if (strlen($slug) > $maxLength) {
                $this->fail('slug', 'too_long', ['max' => $maxLength]);
            }

            if ($this->taken($modelClass, $slug, $ignore, $scope)) {
                $this->fail('slug', 'slug_taken');
            }

            return $slug;
        }

        $base = rtrim(Str::limit(Str::slug($source), $maxLength - self::SUFFIX_RESERVE, ''), '-');

        if ($base === '') {
            $base = Str::kebab(class_basename($modelClass));
        }

        $slug = $base;

        for ($suffix = 2; ($isReserved !== null && $isReserved($slug)) || $this->taken($modelClass, $slug, $ignore, $scope); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $scope
     */
    private function taken(string $modelClass, string $slug, ?Model $ignore, array $scope): bool
    {
        /** @var Builder<Model> $query */
        $query = $modelClass::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withTrashed();
        }

        return $query
            ->where('slug', $slug)
            ->where($scope)
            ->when($ignore?->exists, fn (Builder $query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();
    }
}
