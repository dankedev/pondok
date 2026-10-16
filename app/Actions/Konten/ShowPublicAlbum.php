<?php

namespace App\Actions\Konten;

use App\Models\Album;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Album berfoto beserta fotonya (berpaginasi) untuk portal. Album tanpa foto 404.
 */
class ShowPublicAlbum
{
    public const PHOTOS_PER_PAGE = 24;

    /**
     * @return array{album: Album, photos: LengthAwarePaginator}
     */
    public function handle(string $slug, int $page = 1): array
    {
        $album = Album::query()->where('slug', $slug)->has('photos')->with('category')->first();
        abort_if($album === null, 404);

        $photos = $album->photos()
            ->paginate(self::PHOTOS_PER_PAGE, ['*'], 'page', $page)
            ->withPath(route('portal.albums.show', $album->slug));

        abort_if($page > 1 && $page > $photos->lastPage(), 404);

        return ['album' => $album, 'photos' => $photos];
    }
}
