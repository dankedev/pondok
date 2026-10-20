<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Konten\GetPsbInfo;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

class ShowPsbInfo extends Controller
{
    public function __invoke(GetPsbInfo $psbInfo): View
    {
        return view('portal.psb.info', $psbInfo->handle());
    }
}
