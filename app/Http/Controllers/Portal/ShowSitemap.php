<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Konten\BuildSitemap;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class ShowSitemap extends Controller
{
    public function __invoke(BuildSitemap $buildSitemap): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .view('portal.sitemap', ['entries' => $buildSitemap->handle()])->render();

        return response($xml)->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
