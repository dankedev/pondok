<?php

use App\Http\Middleware\SetPortalSecurityHeaders;
use App\Models\Setting;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
| F105 AC-10 dan sisi CSP AC-15. Kontrak: `GET /tema/{slug}/{hash12}/{path}` bernama `theme-asset` (di luar grup `portal.`), stateless (tanpa sesi/cookie),
| `EnsureModuleIsEnabled:konten,404`, `SetPortalSecurityHeaders`. Melayani hanya berkas yang terdaftar di `assets` manifest tema terpasang
| dengan hash12 = hash `theme.json` terpasang. 200: Content-Type tepat, nosniff, `Cache-Control: public, max-age=31536000, immutable`, ETag/304.
| Selain itu 404 tanpa `immutable`. Tiap test 404 dimulai dari kontrol 200 supaya 404 bukan karena route belum ada.
*/

beforeEach(function () {
    portalBoot();
    themeSandbox();
    themeInstalledSandbox();
    $this->pkg = themePkg(['slug' => 'zz-rute']);
    themePkgPlace($this->pkg);
});

function taUrl(array $pkg, string $path = 'theme.css', ?string $hash = null): string
{
    return "/tema/{$pkg['slug']}/".($hash ?? $pkg['hash12'])."/{$path}";
}

function taBody(TestResponse $response): string
{
    $base = $response->baseResponse;

    return match (true) {
        $base instanceof BinaryFileResponse => (string) file_get_contents($base->getFile()->getPathname()),
        $base instanceof StreamedResponse => $response->streamedContent(),
        default => (string) $response->getContent(),
    };
}

function taAssertMissing(TestResponse $response, string $label): void
{
    $response->assertNotFound();

    expect((string) $response->headers->get('Cache-Control'))->not->toContain('immutable', "{$label}: 404 tidak boleh immutable");
}

it('F105 AC-10: names the route theme-asset and builds /tema/{slug}/{hash12}/{path}', function () {
    expect(route('theme-asset', ['slug' => 'zz-rute', 'hash12' => 'abcdef012345', 'path' => 'fonts/zz-serif.woff2'], false))->toBe('/tema/zz-rute/abcdef012345/fonts/zz-serif.woff2');
});

it('F105 AC-10: serves the css and the font with exact type, nosniff, immutable cache and the file bytes', function () {
    $css = $this->get(taUrl($this->pkg))->assertOk();
    $font = $this->get(taUrl($this->pkg, F105_FONT_PATH))->assertOk();

    expect($css->headers->get('Content-Type'))->toBe('text/css; charset=utf-8')
        ->and($font->headers->get('Content-Type'))->toBe('font/woff2')
        ->and(taBody($css))->toBe($this->pkg['files']['theme.css'])
        ->and(taBody($font))->toBe($this->pkg['files'][F105_FONT_PATH]);

    foreach ([$css, $font] as $response) {
        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=31536000')->toContain('immutable')
            ->and($response->headers->get('ETag'))->not->toBeNull();
    }
});

it('F105 AC-10: answers 304 for a matching ETag', function () {
    $etag = $this->get(taUrl($this->pkg))->assertOk()->headers->get('ETag');

    $again = $this->withHeader('If-None-Match', $etag)->get(taUrl($this->pkg));

    expect($again->getStatusCode())->toBe(304);
});

it('F105 AC-10: is stateless, sending no cookie even when a session cookie is presented, and serves a theme that is not the active one', function () {
    expect(Setting::get('theme.template'))->toBeNull();

    $response = $this->withUnencryptedCookie('laravel_session', 'abc')->get(taUrl($this->pkg))->assertOk();

    expect($response->headers->getCookies())->toBeEmpty()
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();
});

it('F105 AC-10: sends the portal security headers, and the portal csp allows data: images without loosening scripts', function () {
    config(['pondok.portal.csp_enabled' => true]);

    $asset = $this->get(taUrl($this->pkg))->assertOk();
    $home = $this->get('/')->assertOk();
    preg_match('/img-src ([^;]+)/', (string) $home->headers->get('Content-Security-Policy'), $img);
    preg_match('/script-src ([^;]+)/', (string) $home->headers->get('Content-Security-Policy'), $script);

    expect($asset->headers->get('Content-Security-Policy'))->toBe(SetPortalSecurityHeaders::CONTENT_SECURITY_POLICY)
        ->and($img[1] ?? '')->toContain("'self'")->toContain('data:')
        ->and($script[1] ?? '')->toBe("'self'")
        ->and(SetPortalSecurityHeaders::CONTENT_SECURITY_POLICY)->toContain("font-src 'self'");
});

it('F105 AC-10: answers 404 without immutable for unknown, malformed, traversing and unlisted requests', function () {
    $pkg = $this->pkg;
    $this->get(taUrl($pkg))->assertOk();
    file_put_contents(themeInstalledSandbox()."/zz-rute/assets/{$pkg['hash12']}/fonts/zz-lain.woff2", themePkgFontBytes());
    file_put_contents(themeInstalledSandbox().'/zz-rute/previous.json', $pkg['bytes']);

    $cases = [
        'slug tak dikenal' => "/tema/zz-tidak-ada/{$pkg['hash12']}/theme.css",
        'slug huruf besar' => "/tema/ZZ-RUTE/{$pkg['hash12']}/theme.css",
        'slug satu huruf' => "/tema/a/{$pkg['hash12']}/theme.css",
        'hash salah' => taUrl($pkg, 'theme.css', str_repeat('0', 12)),
        'hash huruf besar' => taUrl($pkg, 'theme.css', strtoupper($pkg['hash12'])),
        'hash 11 karakter' => taUrl($pkg, 'theme.css', substr($pkg['hash12'], 0, 11)),
        'hash 13 karakter' => taUrl($pkg, 'theme.css', $pkg['hash12'].'0'),
        'berkas ada di disk tetapi tak ada di manifest' => taUrl($pkg, 'fonts/zz-lain.woff2'),
        'theme.json bukan aset' => taUrl($pkg, 'theme.json'),
        'previous.json bukan aset' => taUrl($pkg, 'previous.json'),
        'path kosong' => "/tema/zz-rute/{$pkg['hash12']}/",
        'traversal %2f' => taUrl($pkg, '..%2f..%2ftheme.json'),
        'traversal %2e%2e' => taUrl($pkg, '%2e%2e/%2e%2e/theme.json'),
        'traversal di dalam fonts' => taUrl($pkg, 'fonts/..%2f..%2f..%2ftheme.json'),
        'path absolut' => taUrl($pkg, '%2fetc%2fpasswd'),
        'backslash' => taUrl($pkg, 'fonts%5C..%5Ctheme.css'),
        'huruf besar pada path' => taUrl($pkg, 'THEME.CSS'),
        'ekstensi ganda' => taUrl($pkg, 'theme.css.php'),
    ];

    foreach ($cases as $label => $url) {
        taAssertMissing($this->get($url), $label);
    }
});

it('F105 AC-10: answers 404 for a symlinked css, a symlinked fonts directory, or anything resolving outside the asset directory', function () {
    $pkg = $this->pkg;
    $this->get(taUrl($pkg))->assertOk();
    $assets = themeInstalledSandbox()."/zz-rute/assets/{$pkg['hash12']}";
    $outside = dirname(themeInstalledSandbox()).'/luar';
    mkdir($outside, 0777, true);
    file_put_contents("{$outside}/rahasia.css", 'RAHASIA');
    file_put_contents("{$outside}/zz-serif.woff2", 'wOF2RAHASIA');

    unlink("{$assets}/theme.css");
    symlink("{$outside}/rahasia.css", "{$assets}/theme.css");
    File::deleteDirectory("{$assets}/fonts");
    symlink($outside, "{$assets}/fonts");

    taAssertMissing($this->get(taUrl($pkg)), 'css symlink');
    taAssertMissing($this->get(taUrl($pkg, F105_FONT_PATH)), 'folder fonts symlink');
});

it('F105 AC-10: stops serving the old hash after the theme is updated, and never serves a theme that declares no assets', function () {
    $old = $this->pkg;
    $new = themePkg(['slug' => 'zz-rute', 'version' => '1.1.0', 'tag' => 'v2']);
    $this->get(taUrl($old))->assertOk();

    themePkgPlace($new);

    $this->get(taUrl($new))->assertOk();
    taAssertMissing($this->get(taUrl($old)), 'hash lama setelah pembaruan');

    $plainBytes = themeInstalledWrite('zz-polos', themeBaseManifest());
    $hash = substr(hash('sha256', $plainBytes), 0, 12);
    mkdir(themeInstalledSandbox()."/zz-polos/assets/{$hash}", 0777, true);
    file_put_contents(themeInstalledSandbox()."/zz-polos/assets/{$hash}/theme.css", 'body{}');

    taAssertMissing($this->get("/tema/zz-polos/{$hash}/theme.css"), 'tema tanpa assets');
});

it('F105 AC-10: answers 404 for a valid asset when the Konten module is off', function () {
    $this->get(taUrl($this->pkg))->assertOk();

    Setting::set('modules.konten.enabled', false);

    taAssertMissing($this->get(taUrl($this->pkg)), 'modul konten mati');
});

it('F105 AC-15: keeps the theme texts in lang/id under theme_assets', function () {
    $texts = trans('pondok.theme_assets');

    expect($texts)->toBeArray()->not->toBeEmpty()
        ->and(collect($texts)->flatten()->every(fn ($text) => is_string($text) && trim($text) !== ''))->toBeTrue();
});
