<?php

use App\Actions\Konten\InstallPortalTheme;
use App\Models\Setting;

/*
| F105 VERIFY (adversarial): bentuk path/slug/hash yang licik di route aset, header 404 (tanpa cookie, nosniff), halaman non-portal tanpa
| stylesheet/`data-pt-theme` walau tema ber-CSS aktif, chrome inti tanpa header/footer_widgets kosong, dan replay paket lama dengan aset.
*/

beforeEach(function () {
    portalBoot();
    portalSeedContent();
    themeSandbox();
    themeInstalledSandbox();
});

function advActivate(string $slug): void
{
    Setting::set('theme.template', $slug);
    app()->forgetScopedInstances();
}

it('F105 VERIFY: answers 404 without immutable, cookie or sniffing for newline, NUL, double-slash and oversized request shapes', function () {
    $pkg = themePkg(['slug' => 'zz-adv-rute']);
    themePkgPlace($pkg);
    $base = "/tema/{$pkg['slug']}/{$pkg['hash12']}";

    $this->get("{$base}/theme.css")->assertOk();

    $urls = [
        'path diakhiri newline' => "{$base}/theme.css%0A",
        'path berisi NUL' => "{$base}/theme.css%00.png",
        'double slash sebelum nama font' => "{$base}/fonts//zz-serif.woff2",
        'slug diakhiri newline' => "/tema/{$pkg['slug']}%0A/{$pkg['hash12']}/theme.css",
        'hash diakhiri newline' => "/tema/{$pkg['slug']}/{$pkg['hash12']}%0A/theme.css",
        'path 5000 karakter' => "{$base}/".str_repeat('a', 5000),
    ];

    foreach ($urls as $label => $url) {
        $response = $this->get($url);

        $response->assertNotFound();
        expect((string) $response->headers->get('Cache-Control'))->not->toContain('immutable', $label)
            ->and($response->headers->getCookies())->toBeEmpty($label)
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff', $label);
    }
});

it('F105 VERIFY: never loads the theme stylesheet or data-pt-theme on the wali portal, the PSB form and the 404 page while a css theme is active', function () {
    Setting::set('modules.portal-wali.enabled', true);
    Setting::set('modules.psb.enabled', true);
    $pkg = themePkg(['slug' => 'zz-adv-non', 'manifest' => ['header' => null, 'footer' => null, 'home' => ['layout' => [['component' => 'quote', 'style' => ['tone' => 'auto']]]]]]);
    themePkgPlace($pkg);
    advActivate('zz-adv-non');

    // Kontrol: beranda memuat stylesheet tema.
    expect($this->get('/')->assertOk()->getContent())->toContain("/tema/zz-adv-non/{$pkg['hash12']}/theme.css");

    foreach (['/wali/masuk' => 200, '/psb' => 200, '/halaman-yang-tidak-ada-sama-sekali' => 404, '/berita/tidak-ada-sama-sekali' => 404] as $url => $status) {
        $response = $this->get($url);
        $response->assertStatus($status);

        expect($response->getContent())->not->toContain('/tema/')->not->toContain('data-pt-theme', $url);
    }
});

it('F105 VERIFY: renders a css theme with the core header and an empty footer_widgets area as a 200 that still carries the stylesheet', function () {
    ftrSet([]);
    $pkg = themePkg(['slug' => 'zz-adv-chrome', 'manifest' => [
        'header' => null,
        'footer' => ['component' => 'container', 'children' => [['component' => 'footer_widgets'], ['component' => 'copyright']]],
        'home' => ['layout' => [['component' => 'quote', 'style' => ['tone' => 'auto']]]],
    ]]);
    themePkgPlace($pkg);
    advActivate('zz-adv-chrome');

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain("/tema/zz-adv-chrome/{$pkg['hash12']}/theme.css")
        ->toMatch('/<body\b[^>]*\bdata-pt-theme="zz-adv-chrome"/')
        ->toContain('<header')
        ->toContain('<footer');
});

it('F105 VERIFY: rejects replaying an older or same-version package with assets and leaves no asset directory of the rejected package', function () {
    kontenBoot();
    themeTestKeys(['k1', 'k2']);
    $current = themePkg(['slug' => 'zz-adv-replay', 'version' => '1.1.0']);
    app(InstallPortalTheme::class)->handle(themePkgEntry($current), $current['bytes'], null, $current['files']);

    $tree = fn (): array => array_map(fn (string $path) => str_replace(themeInstalledSandbox(), '', $path), glob(themeInstalledSandbox().'/zz-adv-replay/{*,assets/*}', GLOB_BRACE) ?: []);
    $before = $tree();
    $manifestBefore = (string) file_get_contents(themeInstalledSandbox().'/zz-adv-replay/theme.json');

    foreach (['lebih rendah' => themePkg(['slug' => 'zz-adv-replay', 'version' => '1.0.0', 'tag' => 'lama']), 'sama' => themePkg(['slug' => 'zz-adv-replay', 'version' => '1.1.0', 'tag' => 'beda'])] as $label => $replay) {
        themeAssertRejected(fn () => app(InstallPortalTheme::class)->handle(themePkgEntry($replay), $replay['bytes'], null, $replay['files']));

        expect($tree())->toBe($before, $label)
            ->and((string) file_get_contents(themeInstalledSandbox().'/zz-adv-replay/theme.json'))->toBe($manifestBefore, $label);
    }
});
