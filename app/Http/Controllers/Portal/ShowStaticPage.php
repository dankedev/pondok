<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Konten\ShowPublicStaticPage;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

class ShowStaticPage extends Controller
{
    public function __invoke(string $page, ShowPublicStaticPage $showPage): View
    {
        return view('portal.pages.show', $showPage->handle($page));
    }
}
