<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Konten\ListPublicPosts;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ReadsPortalQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ListPosts extends Controller
{
    use ReadsPortalQuery;

    public function __invoke(Request $request, ListPublicPosts $listPosts): View
    {
        $filters = [
            'jenis' => $this->queryFilter($request, 'jenis'),
            'kategori' => $this->queryFilter($request, 'kategori'),
            'jenjang' => $this->queryFilter($request, 'jenjang'),
        ];
        $page = $this->queryPage($request);

        return view('portal.posts.index', [
            ...$listPosts->handle($filters, $page),
            'page' => $page,
        ]);
    }
}
