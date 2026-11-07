<?php

use App\Actions\Konten\BuildThemePreviewUrl;
use App\Actions\Konten\DuplicatePortalTheme;
use App\Models\Setting;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

/*
| F111 PR3, verifikasi adversarial pratinjau tema (mode VERIFY): tanda tangan, slug, kebocoran antar-request,
| route selain empat yang diuji, dan Setting yang tidak boleh berubah.
*/

beforeEach(function () {
    portalBoot();
    $this->seed(RolePermissionSeeder::class);
    themeSandbox();
    themeInstalledSandbox();
    $this->admin = kontenUser('super-admin');
    themePkgPlace(themePkg(['slug' => 'zz-aktif']));
    Setting::set('theme.template', 'zz-aktif');
});

function tvSlug(): string
{
    themePkgPlace(themePkg(['slug' => 'zz-sumber']));

    return app(DuplicatePortalTheme::class)->handle('zz-sumber', ['name' => 'Pratinjau Adversarial'], test()->admin);
}

function tvGet(string $url, array $headers = []): TestResponse
{
    app()->forgetScopedInstances();

    return test()->get($url, $headers);
}

function tvNoise(string $html): string
{
    return (string) preg_replace('/(nonce|csrf-token|_token)="[^"]*"/', '$1="X"', $html);
}

function tvCache(TestResponse $response): string
{
    return (string) $response->headers->get('Cache-Control');
}

function tvIsFallback(TestResponse $response, string $slug): void
{
    expect($response->status())->toBe(200)
        ->and($response->getContent())->toContain('data-pt-theme="zz-aktif"')->not->toContain($slug)
        ->and(tvCache($response))->toContain('public')->not->toContain('no-store')
        ->and((string) $response->headers->get('X-Robots-Tag'))->not->toContain('noindex');
}

it('F111 verify: tampering with a signed preview url falls back to the active theme', function (string $case) {
    $slug = tvSlug();
    $signed = app(BuildThemePreviewUrl::class)->handle($slug);

    $url = match ($case) {
        'expires diperpanjang' => preg_replace_callback('/expires=(\d+)/', fn ($m) => 'expires='.($m[1] + 86400), $signed),
        'signature dihapus' => preg_replace('/&signature=[0-9a-f]+/', '', $signed),
        'signature duplikat (yang terakhir salah)' => $signed.'&signature=deadbeef',
        'parameter tambahan' => $signed.'&x=1',
        'urutan query dibalik' => (function () use ($signed): string {
            parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

            return strtok($signed, '?').'?'.http_build_query(array_reverse($query));
        })(),
        'host berbeda' => str_replace('://'.parse_url($signed, PHP_URL_HOST), '://penyerang.test', $signed),
        'skema berbeda' => preg_replace('#^https?://#', str_starts_with($signed, 'https') ? 'http://' : 'https://', $signed),
    };

    tvIsFallback(tvGet($url), $slug);
})->with([
    'expires diperpanjang', 'signature dihapus', 'signature duplikat (yang terakhir salah)', 'parameter tambahan',
    'urutan query dibalik', 'host berbeda', 'skema berbeda',
]);

it('F111 verify: an array query (pratinjau-tema[]=) with a valid signature falls back without error', function () {
    $slug = tvSlug();
    $url = URL::temporarySignedRoute('portal.home', now()->addMinutes(30), ['pratinjau-tema' => [$slug]]);

    tvIsFallback(tvGet($url), $slug);
});

it('F111 verify: the preview is valid exactly at 30 minutes and gone one second later', function () {
    $slug = tvSlug();
    $url = app(BuildThemePreviewUrl::class)->handle($slug);

    $this->travel(30)->minutes();
    expect(tvGet($url)->getContent())->toContain('data-pt-theme="'.$slug.'"');

    $this->travel(1)->seconds();
    tvIsFallback(tvGet($url), $slug);
});

it('F111 verify: an official theme slug can be previewed, an installed but broken one falls back to the core chrome without a 500', function (string $case) {
    $slug = match ($case) {
        'tema resmi' => 'centered',
        'json rusak' => (function (): string {
            themeInstalledWrite('lokal-rusak', '{bukan json');

            return 'lokal-rusak';
        })(),
        'skema tidak didukung' => (function (): string {
            themeInstalledWrite('lokal-versi', [...themeBaseManifest(), 'schema_version' => 999]);

            return 'lokal-versi';
        })(),
    };
    $url = URL::temporarySignedRoute('portal.home', now()->addMinutes(30), ['pratinjau-tema' => $slug]);

    $response = tvGet($url);

    expect($response->status())->toBe(200)
        ->and(tvCache($response))->toContain('no-store');

    if ($case === 'tema resmi') {
        $preview = $response->getContent();
        Setting::set('theme.template', 'centered');

        expect(tvNoise($preview))->toBe(tvNoise(tvGet('/')->getContent()));
    } else {
        expect($response->getContent())->not->toContain('data-pt-theme="'.$slug.'"');
    }
})->with(['tema resmi', 'json rusak', 'skema tidak didukung']);

it('F111 verify: a preview request does not leak into the next plain request, even when it carries stale validators', function () {
    $slug = tvSlug();
    $plain = tvGet('/')->assertOk();
    $plainEtag = (string) $plain->headers->get('ETag');

    $preview = tvGet(app(BuildThemePreviewUrl::class)->handle($slug), ['If-None-Match' => $plainEtag]);
    $previewEtag = (string) $preview->headers->get('ETag');

    expect($preview->status())->toBe(200)->and(tvCache($preview))->toContain('no-store')->not->toContain('public');

    $after = tvGet('/', ['If-None-Match' => $previewEtag]);
    tvIsFallback($after, $slug);
    expect((string) $after->headers->get('ETag'))->toBe($plainEtag)
        ->and(tvGet('/')->getContent())->toBe($plain->getContent());
});

it('F111 verify: a conditional preview request (matching ETag, 304) still never becomes publicly cacheable', function () {
    $slug = tvSlug();
    $url = app(BuildThemePreviewUrl::class)->handle($slug);
    $etag = (string) tvGet($url)->headers->get('ETag');

    $response = tvGet($url, ['If-None-Match' => $etag]);

    expect(tvCache($response))->toContain('no-store')->not->toContain('public')
        ->and((string) $response->headers->get('X-Robots-Tag'))->toContain('noindex');
});

it('F111 verify: other portal routes keep private no-store and do not error under a preview', function (string $route, int $status) {
    $slug = tvSlug();
    $parameters = $route === 'portal.pages.show' ? ['page' => 'halaman-tidak-ada'] : [];

    $response = tvGet(app(BuildThemePreviewUrl::class)->handle($slug, $route, $parameters));

    expect($response->status())->toBe($status)
        ->and(tvCache($response))->toContain('no-store')->not->toContain('public')
        ->and((string) $response->headers->get('X-Robots-Tag'))->toContain('noindex');
})->with([
    'video' => ['portal.videos.index', 200],
    'sitemap' => ['portal.sitemap', 200],
    'robots' => ['portal.robots', 200],
    '404' => ['portal.pages.show', 404],
]);

it('F111 verify: the psb info route (outside the portal. group) with a preview does not error', function () {
    $slug = tvSlug();
    $url = URL::temporarySignedRoute('psb.info', now()->addMinutes(30), ['pratinjau-tema' => $slug]);

    $response = tvGet($url);

    expect($response->status())->toBeIn([200, 404])
        ->and(tvCache($response))->toContain('no-store')->not->toContain('public');
});

it('F111 verify: with the konten module off a preview of / does not error and stays private no-store', function () {
    $slug = tvSlug();
    Setting::set('modules.konten.enabled', false);

    $home = tvGet(app(BuildThemePreviewUrl::class)->handle($slug));

    expect($home->status())->toBeIn([200, 404])->and(tvCache($home))->toContain('no-store')->not->toContain('public');
});

// Temuan: `EnsureModuleIsEnabled` (prioritas middleware lebih awal) membuang 404 sebelum `PreviewPortalTheme` berjalan,
// jadi respons ini tidak mendapat `no-store`/`noindex`. Tidak bocor ke cache publik (`no-cache, private`), tetapi menyimpang dari AC-9/rencana bagian 3.
it('F111 verify: with the konten module off a preview of /berita is a 404 with private no-store and noindex', function () {
    $slug = tvSlug();
    Setting::set('modules.konten.enabled', false);

    $response = tvGet(app(BuildThemePreviewUrl::class)->handle($slug, 'portal.posts.index'));

    expect($response->status())->toBe(404)
        ->and(tvCache($response))->toContain('no-store')->not->toContain('public')
        ->and((string) $response->headers->get('X-Robots-Tag'))->toContain('noindex');
});

it('F111 verify: previews never write Setting and the plain home html is identical before and after', function () {
    $slug = tvSlug();
    $settings = fn (): array => Setting::query()->orderBy('key')->get()->map->getAttributes()->all();
    $strip = fn (string $html): string => (string) preg_replace('/(nonce|csrf-token|_token)="[^"]*"/', '$1="X"', $html);

    $before = $strip(tvGet('/')->getContent());
    $snapshot = $settings();

    foreach (['portal.home', 'portal.posts.index', 'portal.albums.index', 'portal.videos.index', 'portal.robots'] as $route) {
        tvGet(app(BuildThemePreviewUrl::class)->handle($slug, $route))->assertOk();
    }

    expect($settings())->toBe($snapshot)
        ->and(Setting::get('theme.template'))->toBe('zz-aktif')
        ->and($strip(tvGet('/')->getContent()))->toBe($before);
});
