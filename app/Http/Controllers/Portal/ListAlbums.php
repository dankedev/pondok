<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Konten\ListPublicAlbums;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ReadsPortalQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ListAlbums extends Controller
{
    use ReadsPortalQuery;

    public function __invoke(Request $request, ListPublicAlbums $listAlbums): View
    {
        $category = $this->queryFilter($request, 'kategori');
        $page = $this->queryPage($request);

        return view('portal.albums.index', [
            ...$listAlbums->handle($category, $page),
            'page' => $page,
        ]);
    }
}
