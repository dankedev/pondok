<?php

namespace App\Actions\Konten;

use App\Models\Category;
use App\Models\Post;
use App\Models\Unit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Daftar post terbit untuk portal (`/berita`), disaring jenis, kategori, dan jenjang.
 * Filter yang tidak dikenali dan halaman di luar jangkauan menjadi 404.
 */
class ListPublicPosts
{
    public const PER_PAGE = 9;

    /**
     * @param  array{jenis?: ?string, kategori?: ?string, jenjang?: ?string}  $filters
     * @return array{posts: LengthAwarePaginator, type: ?string, category: ?Category, unit: ?Unit, categories: Collection<int, Category>, units: Collection<int, Unit>, types: list<string>, filters: array<string, string>}
     */
    public function handle(array $filters, int $page = 1): array
    {
        $type = $filters['jenis'] ?? null;
        abort_if($type !== null && ! in_array($type, Post::TYPES, true), 404);

        $category = null;
        if (($filters['kategori'] ?? null) !== null) {
            $category = Category::query()->ofType(Category::TYPE_POST)->where('slug', $filters['kategori'])->first();
            abort_if($category === null, 404);
        }

        $allUnits = Unit::query()->orderBy('sort_order')->get();
        $unit = null;
        if (($filters['jenjang'] ?? null) !== null) {
            $unit = $allUnits->firstWhere('code', Str::upper($filters['jenjang']));
            abort_if($unit === null, 404);
        }

        $applied = array_filter([
            'jenis' => $type,
            'kategori' => $category?->slug,
            'jenjang' => $unit === null ? null : Str::lower($unit->code),
        ]);

        $posts = Post::query()
            ->published()
            ->when($type !== null, fn ($query) => $query->ofType($type))
            ->when($category !== null, fn ($query) => $query->where('category_id', $category->getKey()))
            ->when($unit !== null, fn ($query) => $query->where('unit_id', $unit->getKey()))
            ->with(['category', 'unit'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page)
            ->withPath(route('portal.posts.index'))
            ->appends($applied);

        abort_if($page > 1 && $page > $posts->lastPage(), 404);

        $categories = Category::query()
            ->ofType(Category::TYPE_POST)
            ->whereHas('posts', fn ($query) => $query->published())
            ->orderBy('name')
            ->get();

        $usedTypes = Post::query()->published()->distinct()->pluck('type')->all();
        $usedUnitIds = Post::query()->published()->whereNotNull('unit_id')->distinct()->pluck('unit_id')->all();

        // Chip tanpa berita terbit disembunyikan (filter aktif tetap tampil agar bisa dilepas); jenjang hanya bila ≥2.
        $types = array_values(array_filter(Post::TYPES, fn (string $item): bool => in_array($item, $usedTypes, true) || $item === $type));
        $units = $allUnits->filter(fn (Unit $item): bool => in_array($item->getKey(), $usedUnitIds, true) || $item->is($unit))->values();

        return [
            'posts' => $posts,
            'type' => $type,
            'types' => $types,
            'category' => $category,
            'unit' => $unit,
            'categories' => $categories,
            'units' => $units->count() >= 2 ? $units : collect(),
            'filters' => $applied,
        ];
    }
}
