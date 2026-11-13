<?php

use App\Actions\Konten\ListPortalThemes;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/*
| F105 AC-11 dan AC-12 (render dan fallback). Tema terpasang ber-`assets.css` yang lolos pemeriksaan runtime (berkas ada + size cocok):
| `layouts/public` menambah `<link rel="stylesheet" href="/tema/<slug>/<hash12>/theme.css">` RELATIF setelah `@vite` dan `<style data-portal-theme>`,
| serta `<body data-pt-theme="<slug>">`. Tema tanpa css: keluaran tak berubah (tanpa `data-pt*`). Berkas hilang atau size beda: HTTP 200 dengan chrome inti,
| satu `Log::warning` per slug, dan `ListPortalThemes` `valid=false` berpesan. Aset/atribut kontrak per komponen ada di ThemeMarkupContractTest.
*/

beforeEach(function () {
    portalBoot();
    portalSeedContent();
    themeSandbox();
    themeInstalledSandbox();
    blkSet([
        blk('hero', ['eyebrow' => 'Label hero', 'title' => 'Judul hero terpasang', 'text' => 'Teks hero', 'image' => 'portal/blocks/hero.jpg', 'image_alt' => 'Gedung', 'primary' => navItem('posts', 'Tombol hero')]),
        blk('quote', ['text' => 'Kutipan terpasang uji', 'source' => 'Sumber uji']),
    ]);
});

/**
 * @param  array<string, mixed>  $options
 * @return array<string, mixed>
 */
function arPkg(array $options = []): array
{
    return themePkg([...$options, 'manifest' => ['header' => null, 'footer' => null, 'home' => ['layout' => [['component' => 'quote', 'style' => ['tone' => 'auto']]]]]]);
}

function arActivate(string $slug): void
{
    Setting::set('theme.template', $slug);
    app()->forgetScopedInstances();
}

function arSquash(string $html): string
{
    return trim((string) preg_replace('/>\s+</', '><', (string) preg_replace('/\s+/', ' ', $html)));
}

/**
 * @return list<string>
 */
function arThemeLinks(string $html, string $slug): array
{
    preg_match_all('/<link\b[^>]*>/i', $html, $tags);

    return array_values(array_filter($tags[0], fn (string $tag) => str_contains($tag, "/tema/{$slug}/")));
}

it('F105 AC-11: links a relative stylesheet after the admin colours and sets data-pt-theme on body, keeping the portal cacheable and stateless', function () {
    themeSet(['primary' => '#1f2a5e']);
    $pkg = arPkg(['slug' => 'zz-render']);
    themePkgPlace($pkg);
    arActivate('zz-render');

    $response = $this->get('/')->assertOk();
    $html = $response->getContent();
    $links = arThemeLinks($html, 'zz-render');
    $href = "/tema/zz-render/{$pkg['hash12']}/theme.css";

    expect($links)->toHaveCount(1)
        ->and($links[0])->toContain('rel="stylesheet"')->toContain("href=\"{$href}\"")
        ->and(strpos($html, '<style data-portal-theme'))->toBeInt()
        ->and(strpos($html, $href))->toBeGreaterThan(strpos($html, '<style data-portal-theme'))
        ->and(strpos($html, $href))->toBeLessThan(strpos($html, '</head>'))
        ->and($html)->toMatch('/<body\b[^>]*\bdata-pt-theme="zz-render"/')
        ->and($html)->not->toContain('http://localhost/tema/')
        ->and($response->headers->get('Cache-Control'))->toContain('public')
        ->and($response->headers->get('ETag'))->not->toBeNull()
        ->and($response->headers->getCookies())->toBeEmpty()
        ->and(strtolower($html))->not->toContain('csrf-token')->not->toContain('livewire')->not->toContain('_token');
});

it('F105 AC-11: also themes the other portal pages and the PSB info page', function () {
    Setting::set('modules.psb.enabled', true);
    $pkg = arPkg(['slug' => 'zz-halaman']);
    themePkgPlace($pkg);
    arActivate('zz-halaman');

    foreach (['/', '/berita', '/info-psb'] as $url) {
        $html = $this->get($url)->assertOk()->getContent();

        expect(arThemeLinks($html, 'zz-halaman'))->toHaveCount(1, $url)
            ->and($html)->toMatch('/<body\b[^>]*\bdata-pt-theme="zz-halaman"/');
    }
});

it('F105 AC-11: renders a theme without css exactly as before, and the css theme differs only by the link and the data-pt attributes', function () {
    $plainSlug = themeInstalledWrite('zz-polos', [
        ...themeBaseManifest(), 'header' => null, 'footer' => null, 'home' => ['layout' => [['component' => 'quote', 'style' => ['tone' => 'auto']]]],
    ]);
    arActivate('zz-polos');
    $plain = $this->get('/')->assertOk()->getContent();

    $pkg = arPkg(['slug' => 'zz-css']);
    themePkgPlace($pkg);
    arActivate('zz-css');
    $themed = $this->get('/')->assertOk()->getContent();

    $stripped = preg_replace('/ data-pt(?:-[a-z]+)*="[^"]*"/', '', (string) preg_replace('/\s*<link\b[^>]*\/tema\/[^>]*>/i', '', $themed));

    expect($plainSlug)->toBeString()
        ->and($plain)->not->toContain('data-pt')->not->toContain('/tema/')
        ->and($themed)->toContain('data-pt="quote"')
        ->and(arSquash((string) $stripped))->toBe(arSquash($plain));
});

it('F105 AC-11: lets a theme without css follow the admin colours while a css theme loads its own stylesheet after them', function () {
    themeSet(['primary' => '#1f2a5e']);
    themeInstalledWrite('zz-polos', [...themeBaseManifest(), 'header' => null, 'footer' => null]);
    arActivate('zz-polos');

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('data-portal-theme')->not->toContain('/tema/');

    $pkg = arPkg(['slug' => 'zz-css-admin']);
    themePkgPlace($pkg);
    arActivate('zz-css-admin');
    $themed = $this->get('/')->assertOk()->getContent();

    expect($themed)->toContain('data-portal-theme')
        ->and(strpos($themed, "/tema/zz-css-admin/{$pkg['hash12']}/theme.css"))->toBeGreaterThan(strpos($themed, '<style data-portal-theme'));
});

it('F105 AC-11: never loads the theme stylesheet in the admin panel', function () {
    $pkg = arPkg(['slug' => 'zz-admin']);
    themePkgPlace($pkg);
    arActivate('zz-admin');
    // Kontrol: portal publik memuat stylesheet tema yang sama.
    expect(arThemeLinks($this->get('/')->assertOk()->getContent(), 'zz-admin'))->toHaveCount(1);
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    $login = $this->get('/admin/login')->assertOk()->getContent();

    expect($login)->not->toContain('/tema/')->not->toContain('data-pt-theme');
});

it('F105 AC-12: falls back to the core chrome with a 200 and one warning per slug when css or a font is missing or has the wrong size', function () {
    $core = arSquash($this->get('/')->assertOk()->getContent());
    $cases = [
        'css hilang' => fn (string $assets) => unlink("{$assets}/theme.css"),
        'css ukuran beda' => fn (string $assets) => file_put_contents("{$assets}/theme.css", 'body{}'),
        'font hilang' => fn (string $assets) => unlink("{$assets}/".F105_FONT_PATH),
        'font ukuran beda' => fn (string $assets) => file_put_contents("{$assets}/".F105_FONT_PATH, 'wOF2'),
    ];
    $slugs = [];

    Log::spy();

    foreach ($cases as $label => $break) {
        $pkg = arPkg(['slug' => themeUniqueSlug()]);
        themePkgPlace($pkg);
        arActivate($pkg['slug']);
        $assets = themeInstalledSandbox()."/{$pkg['slug']}/assets/{$pkg['hash12']}";

        // Kontrol: sebelum dirusak, tema memuat stylesheet-nya.
        expect(arThemeLinks($this->get('/')->assertOk()->getContent(), $pkg['slug']))->toHaveCount(1, "{$label}: kontrol");
        expect(collect(app(ListPortalThemes::class)->handle())->firstWhere('slug', $pkg['slug']))->toMatchArray(['valid' => true]);

        $break($assets);
        app()->forgetScopedInstances();

        foreach (['/', '/berita', '/'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            expect($html)->not->toContain('/tema/')->not->toContain('data-pt-theme', "{$label}: {$url}");
        }

        expect(arSquash($this->get('/')->getContent()))->toBe($core, "{$label}: beranda memakai chrome dan komponen inti");

        $entry = collect(app(ListPortalThemes::class)->handle())->firstWhere('slug', $pkg['slug']);
        expect($entry['valid'])->toBeFalse("{$label}: valid=false")
            ->and($entry['error'])->toBeString()->not->toBe('')->not->toStartWith('pondok.');

        $slugs[$label] = $pkg['slug'];
    }

    foreach ($slugs as $label => $slug) {
        Log::shouldHaveReceived('warning')->withArgs(fn (...$args): bool => str_contains(json_encode($args), $slug))->once();
    }
});
