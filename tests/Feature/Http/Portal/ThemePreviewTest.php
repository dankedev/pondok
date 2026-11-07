<?php

use App\Actions\Konten\BuildThemePreviewUrl;
use App\Actions\Konten\DuplicatePortalTheme;
use App\Models\Setting;
use App\Models\StaticPage;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

/*
| F111 PR3, AC-9, AC-10, AC-12 (rencana F110). Kontrak yang diasumsikan:
| - `App\Actions\Konten\BuildThemePreviewUrl::handle(string $slug, string $route = 'portal.home', array $parameters = []): string`
|   mengembalikan URL absolut bertanda tangan (`URL::temporarySignedRoute`, 30 menit, query `pratinjau-tema=<slug>`), dibangun dari
|   `config('app.url')` sekalipun request saat ini (mis. panel admin) berhost lain.
| - Middleware `PreviewPortalTheme` di `$portalHeaders`: query + tanda tangan sah + slug tema terpasang yang valid → slug itu menjadi tema
|   efektif (tiga titik baca), respons `Cache-Control: private, no-store` dan `X-Robots-Tag: noindex`. Selain itu tidak melakukan apa pun.
| - `theme.template` (Setting) tidak pernah ditulis oleh pratinjau.
*/

beforeEach(function () {
    portalBoot();
    $this->seed(RolePermissionSeeder::class);
    themeSandbox();
    themeInstalledSandbox();
    $this->admin = kontenUser('super-admin');
});

/** Tema sumber ber-CSS terpasang, diduplikat jadi `lokal-*`; mengembalikan slug duplikat. */
function tpDuplicate(string $source = 'zz-sumber', string $name = 'Pratinjau Saya'): string
{
    if (! is_dir(themeInstalledSandbox()."/{$source}")) {
        themePkgPlace(themePkg(['slug' => $source]));
    }

    return app(DuplicatePortalTheme::class)->handle($source, ['name' => $name], test()->admin);
}

function tpRequest(string $url): TestResponse
{
    app()->forgetScopedInstances();

    return test()->get($url);
}

/** Beranda dengan slug dan URL css dinetralkan, serta token acak diseragamkan. */
function tpNormalize(string $html): string
{
    $html = preg_replace('#/tema/[^/"\']+/[0-9a-f]{12}/theme\.css#', '/tema/CSS', $html);
    $html = preg_replace('/data-pt-theme="[^"]*"/', 'data-pt-theme="X"', (string) $html);
    $html = preg_replace('/(nonce|csrf-token|_token)="[^"]*"/', '$1="X"', (string) $html);

    return (string) preg_replace('/\s+/', ' ', (string) $html);
}

function tpCacheControl($response): string
{
    return (string) $response->headers->get('Cache-Control');
}

it('F111 AC-9: renders the local theme on /, /berita, /galeri and a static page with private no-store and noindex', function () {
    $slug = tpDuplicate();
    $hash = substr(hash('sha256', (string) themeInstalledFile($slug)), 0, 12);
    Setting::set('theme.template', 'default');
    $before = Setting::get('theme.template');
    StaticPage::factory()->published()->create(['slug' => 'tentang-tema']);

    foreach ([['portal.home', []], ['portal.posts.index', []], ['portal.albums.index', []], ['portal.pages.show', ['page' => 'tentang-tema']]] as [$route, $params]) {
        $response = tpRequest(app(BuildThemePreviewUrl::class)->handle($slug, $route, $params));
        expect($response->status())->toBe(200, "status {$route}");
        $html = $response->getContent();

        expect($html)->toContain('data-pt-theme="'.$slug.'"')
            ->and($html)->toContain("/tema/{$slug}/{$hash}/theme.css")
            ->and(tpCacheControl($response))->toContain('private')->toContain('no-store')->not->toContain('public')
            ->and((string) $response->headers->get('X-Robots-Tag'))->toContain('noindex')
            ->and(Setting::get('theme.template'))->toBe($before, "theme.template tidak berubah di {$route}");
    }
});

it('F111 AC-9: a preview url built during an admin request on another host still validates on the portal', function () {
    config(['app.url' => 'http://pondok.test']);
    $slug = tpDuplicate();

    URL::forceRootUrl('http://admin.pondok-lain.test');
    $url = app(BuildThemePreviewUrl::class)->handle($slug);
    URL::forceRootUrl(null);

    expect($url)->toStartWith('http://pondok.test/')->toContain('pratinjau-tema='.$slug)->toContain('signature=');

    $response = tpRequest($url)->assertOk();

    expect($response->getContent())->toContain('data-pt-theme="'.$slug.'"')
        ->and(tpCacheControl($response))->toContain('no-store');
});

it('F111 AC-10: serves the active theme with normal public cache headers when the preview is not valid', function (string $case) {
    $slug = tpDuplicate();
    themePkgPlace(themePkg(['slug' => 'zz-aktif']));
    Setting::set('theme.template', 'zz-aktif');

    $url = match ($case) {
        'tanpa tanda tangan' => url('/')."?pratinjau-tema={$slug}",
        'tanda tangan salah' => preg_replace('/signature=([0-9a-f]{2})/', 'signature=00', app(BuildThemePreviewUrl::class)->handle($slug)),
        'tanda tangan untuk slug lain' => str_replace("pratinjau-tema={$slug}", 'pratinjau-tema=zz-aktif', app(BuildThemePreviewUrl::class)->handle($slug)),
        'slug tidak terpasang' => URL::temporarySignedRoute('portal.home', now()->addMinutes(30), ['pratinjau-tema' => 'lokal-tidak-ada']),
        'slug tidak valid' => URL::temporarySignedRoute('portal.home', now()->addMinutes(30), ['pratinjau-tema' => '../etc']),
        'kedaluwarsa' => (function () use ($slug): string {
            $signed = app(BuildThemePreviewUrl::class)->handle($slug);
            test()->travel(31)->minutes();

            return $signed;
        })(),
    };

    $response = tpRequest($url)->assertOk();

    expect($response->getContent())->toContain('data-pt-theme="zz-aktif"')->not->toContain($slug)
        ->and(tpCacheControl($response))->toContain('public')->not->toContain('no-store')
        ->and($response->headers->has('X-Robots-Tag') ? (string) $response->headers->get('X-Robots-Tag') : '')->not->toContain('noindex')
        ->and(Setting::get('theme.template'))->toBe('zz-aktif');
})->with(['tanpa tanda tangan', 'tanda tangan salah', 'tanda tangan untuk slug lain', 'slug tidak terpasang', 'slug tidak valid', 'kedaluwarsa']);

it('F111 AC-10: a request without the query is unchanged (public cache, no noindex)', function () {
    $response = tpRequest('/')->assertOk();

    expect(tpCacheControl($response))->toContain('public')->not->toContain('no-store')
        ->and($response->headers->has('X-Robots-Tag') ? (string) $response->headers->get('X-Robots-Tag') : '')->not->toContain('noindex');
});

it('F111 AC-12: the preview of a duplicate equals the home of its active source except slug and css url', function () {
    $slug = tpDuplicate();
    Setting::set('theme.template', 'zz-sumber');

    $source = tpRequest('/')->assertOk()->getContent();
    $preview = tpRequest(app(BuildThemePreviewUrl::class)->handle($slug))->assertOk()->getContent();

    expect($source)->toContain('data-pt-theme="zz-sumber"')
        ->and($preview)->toContain('data-pt-theme="'.$slug.'"')->not->toContain('zz-sumber')
        ->and(tpNormalize($preview))->toBe(tpNormalize($source));
});

it('F111 AC-12: <style data-portal-theme> is identical for the duplicate of a css-less theme with palette and surface', function () {
    themeInstalledWrite('zz-polos', [...themeBaseManifest(), 'palette' => 'hijau', 'surface' => 'krem']);
    $slug = app(DuplicatePortalTheme::class)->handle('zz-polos', ['name' => 'Salinan Palet'], $this->admin);
    Setting::set('theme.template', 'zz-polos');

    $source = tpRequest('/')->assertOk()->getContent();
    $preview = tpRequest(app(BuildThemePreviewUrl::class)->handle($slug))->assertOk()->getContent();

    preg_match('#<style data-portal-theme>.*?</style>#s', $source, $sourceStyle);
    preg_match('#<style data-portal-theme>.*?</style>#s', $preview, $previewStyle);

    expect($sourceStyle)->not->toBe([])->and($sourceStyle[0])->toContain('--color-primary-')
        ->and($previewStyle[0] ?? null)->toBe($sourceStyle[0])
        ->and(tpNormalize($preview))->toBe(tpNormalize($source));
});
