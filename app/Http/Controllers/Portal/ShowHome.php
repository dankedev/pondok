<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Konten\GetPortalHomeData;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

class ShowHome extends Controller
{
    public function __invoke(GetPortalHomeData $homeData): View
    {
        return view('portal.home', $homeData->handle());
    }
}
