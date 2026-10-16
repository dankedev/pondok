<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class ShowRobots extends Controller
{
    public function __invoke(): Response
    {
        return response()
            ->view('portal.robots', ['sitemapUrl' => route('portal.sitemap')])
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
