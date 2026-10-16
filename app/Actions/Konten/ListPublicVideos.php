<?php

namespace App\Actions\Konten;

use App\Models\Video;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Daftar video YouTube portal sesuai urutan `Video::ordered()`.
 */
class ListPublicVideos
{
    public const PER_PAGE = 9;

    public function handle(int $page = 1): LengthAwarePaginator
    {
        $videos = Video::query()
            ->ordered()
            ->paginate(self::PER_PAGE, ['*'], 'page', $page)
            ->withPath(route('portal.videos.index'));

        abort_if($page > 1 && $page > $videos->lastPage(), 404);

        return $videos;
    }
}
