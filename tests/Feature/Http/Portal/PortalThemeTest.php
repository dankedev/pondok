<?php

use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;

/*
| F070 AC-1, AC-10, AC-11, AC-12, AC-13 (HTML). Kontrak: layout publik memanggil `@fonts($theme['font_aliases'])` dan, bila
| `$theme['css']` ada, mencetak `<style data-portal-theme>:root{...}</style>` setelah `@vite`. Panel Filament tidak ikut tema.
*/

const THEME_FULL = ['primary' => '#1f2a5e', 'accent' => '#ffd452', 'font' => 'nunito', 'radius' => 'large'];

/**
 * Ganti Vite dengan fake yang mencatat argumen `@fonts`.
 */
function themeFakeVite(): object
{
    $fake = new class
    {
        /** @var list<mixed> */
        public array $fontCalls = [];

        public function __invoke($entrypoints, $buildDirectory = null): HtmlString
        {
            return new HtmlString('');
        }

        public function fonts($aliases = null): HtmlString
        {
            $this->fontCalls[] = $aliases;

            return new HtmlString('');
        }

        public function __call($method, $parameters): string
        {
            return '';
        }
    };

    app()->instance(Vite::class, $fake);

    return $fake;
}

it('AC-1: renders the home page without a theme block and with the default fonts', function () {
    portalBoot();
    themeBuild();
    $vite = themeFakeVite();

    $this->get(route('portal.home'))->assertOk()->assertDontSee('data-portal-theme', escape: false);

    expect($vite->fontCalls)->toBe([null]);
});

it('AC-11: prints the theme block and requests the theme fonts on every public page', function (string $url, int $status) {
    portalBoot();
    portalPsbOpen();
    themeBuild();
    themeSet(THEME_FULL);
    $vite = themeFakeVite();

    $html = $this->get($url)->assertStatus($status)->getContent();

    expect($html)->toContain('<style data-portal-theme>:root{')->toContain('--color-primary-900:')
        ->and($vite->fontCalls)->toBe([['nunito', 'noto-naskh-arabic']]);
})->with([
    'home' => ['/', 200],
    'psb' => ['/psb', 200],
    'not found' => ['/tidak-ada-halaman', 404],
]);

it('AC-11: puts the theme block after the stylesheet so it wins over the build layer', function () {
    portalBoot();
    themeBuild();
    themeSet(['primary' => '#1f2a5e']);
    app()->instance(Vite::class, new class
    {
        public function __invoke($entrypoints, $buildDirectory = null): HtmlString
        {
            return new HtmlString('<link rel="stylesheet" href="/build/app-test.css">');
        }

        public function __call($method, $parameters): HtmlString
        {
            return new HtmlString('');
        }
    });

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('data-portal-theme')
        ->and(strpos($html, 'data-portal-theme'))->toBeGreaterThan(strpos($html, '/build/app-test.css'));
});

it('AC-10: never prints a corrupt stored value on the page', function () {
    portalBoot();
    themeBuild();
    themeSet(['primary' => '#000000;}</style><script>alert(1)</script>', 'accent' => ['x'], 'font' => 'x', 'radius' => 'large;}']);
    themeFakeVite();

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('data-portal-theme')->not->toContain('alert(1)')->not->toContain('</style><script');

    themeSet(['primary' => "#1f2a5e\n", 'accent' => null, 'font' => null, 'radius' => null]);

    expect($this->get('/')->assertOk()->getContent())->not->toContain('data-portal-theme');
});

it('AC-12: still renders with the real Vite when the chosen font is missing from the build', function (?array $manifest) {
    portalBoot();
    $dir = themeBuild($manifest);
    themeSet(['primary' => '#1f2a5e', 'font' => 'nunito']);
    app()->instance(Vite::class, new Vite);

    expect(is_file($dir.'/build/manifest.json'))->toBeTrue();

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('data-portal-theme')->toContain('--color-primary-900:')->not->toContain("'Nunito'");
})->with([
    'alias missing' => [['plus-jakarta-sans', 'noto-naskh-arabic']],
    'no font manifest' => [null],
]);

it('AC-13: leaves the Filament panel without the portal theme', function () {
    kontenBoot();
    themeSet(THEME_FULL);

    expect($this->get('/admin/login')->assertOk()->getContent())->not->toContain('data-portal-theme');

    $html = $this->actingAs(kontenUser('super-admin'))->get('/admin/site/theme')->assertOk()->getContent();

    expect($html)->not->toContain('data-portal-theme');
});
