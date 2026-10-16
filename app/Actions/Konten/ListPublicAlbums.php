<?php

namespace App\Actions\Konten;

use App\Models\Album;
use App\Models\Category;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Daftar album berfoto untuk galeri portal, opsional disaring kategori album.
 */
class ListPublicAlbums
{
    public const PER_PAGE = 12;

    /**
     * @return array{albums: LengthAwarePaginator, category: ?Category, categories: Collection<int, Category>}
     */
    public function handle(?string $categorySlug, int $page = 1): array
    {
        $category = null;
        if ($categorySlug !== null) {
            $category = Category::query()->ofType(Category::TYPE_ALBUM)->where('slug', $categorySlug)->first();
            abort_if($category === null, 404);
        }

        $albums = Album::query()
            ->has('photos')
            ->when($category !== null, fn ($query) => $query->where('category_id', $category->getKey()))
            ->withCount('photos')
            ->with(['coverPhoto', 'category'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page)
            ->withPath(route('portal.albums.index'))
            ->appends(array_filter(['kategori' => $category?->slug]));

        abort_if($page > 1 && $page > $albums->lastPage(), 404);

        return [
            'albums' => $albums,
            'category' => $category,
            'categories' => Category::query()->ofType(Category::TYPE_ALBUM)->whereHas('albums', fn ($query) => $query->has('photos'))->orderBy('name')->get(),
        ];
    }
}
