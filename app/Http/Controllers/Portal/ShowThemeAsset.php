<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Konten\ResolveThemeAssetPath;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Menyajikan CSS dan font tema terpasang (F105) lewat route stateless `theme-asset`; hanya berkas yang diselesaikan `ResolveThemeAssetPath`.
 * URL memuat hash manifest, jadi isinya tak pernah berubah (immutable); 404 tidak pernah immutable.
 */
class ShowThemeAsset extends Controller
{
    private const TYPES = ['css' => 'text/css; charset=utf-8', 'woff2' => 'font/woff2'];

    public function __invoke(Request $request, ResolveThemeAssetPath $assets, string $slug, string $hash12, string $path): Response
    {
        $file = $assets->handle($slug, $hash12, $path);
        $content = $file === null ? false : @file_get_contents($file);

        abort_if($content === false, 404);

        $response = response($content, 200, ['Content-Type' => self::TYPES[pathinfo($path, PATHINFO_EXTENSION)]]);
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setImmutable();
        $response->setEtag(md5($content));
        $response->isNotModified($request);

        return $response;
    }
}
