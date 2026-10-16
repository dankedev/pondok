<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Konten\ShowPublicAlbum;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ReadsPortalQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShowAlbum extends Controller
{
    use ReadsPortalQuery;

    public function __invoke(Request $request, string $slug, ShowPublicAlbum $showAlbum): View
    {
        $page = $this->queryPage($request);

        return view('portal.albums.show', [
            ...$showAlbum->handle($slug, $page),
            'page' => $page,
        ]);
    }
}
