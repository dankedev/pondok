<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Konten\ListPublicVideos;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ReadsPortalQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ListVideos extends Controller
{
    use ReadsPortalQuery;

    public function __invoke(Request $request, ListPublicVideos $listVideos): View
    {
        $page = $this->queryPage($request);

        return view('portal.videos.index', [
            'videos' => $listVideos->handle($page),
            'page' => $page,
        ]);
    }
}
