<?php

use App\Jobs\Konten\InstallThemeFromCatalogJob;
use App\Models\Setting;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| F105 AC-9. Job: manifest -> sha/size/tanda tangan -> skema -> tiap berkas dari DIREKTORI `entry.url` + path manifest lewat `ThemeCatalogClient`
| (https, host sama, redirect terbatas, batas per berkas = min(size terdeklarasi, batas tipe)) -> `ValidateThemePackage` -> pasang. Tenggat total 150 dtk dihitung
| dengan jam aplikasi (`now()`), `$timeout` job 180. Kegagalan = status `failed` berpesan Indonesia dan tema lama tak berubah. Entri katalog tak berubah.
| Tiap kasus gagal diperiksa sampai permintaan CSS terjadi (kontrol), supaya `failed` bukan karena skema `assets` belum dikenal.
*/

beforeEach(function () {
    kontenBoot();
    themeSandbox();
    themeInstalledSandbox();
    themeTestKeys();
    $this->admin = kontenUser('super-admin');
});

/**
 * Fake katalog + berkas paket. `$overrides` = path relatif (atau `theme.json`) => respons/Closure pengganti.
 *
 * @param  array<string, mixed>  $pkg
 * @param  array<string, mixed>  $overrides
 */
function jaFake(array $pkg, array $overrides = []): void
{
    $entry = themePkgEntry($pkg);
    $base = dirname($entry['url']);
    $routes = [THEME_CATALOG_URL => Http::response(json_encode(['themes' => [$entry]]), 200)];

    foreach (['theme.json' => $pkg['bytes'], ...$pkg['files']] as $path => $bytes) {
        $routes["{$base}/{$path}"] = $overrides[$path] ?? Http::response($bytes, 200);
    }

    themeFakeHttp($routes);
}

function jaRun(string $slug): void
{
    Setting::set("themes.install.{$slug}", ['state' => 'queued', 'token' => 'token-1', 'message' => null, 'at' => now()->toIso8601String()]);
    $job = new InstallThemeFromCatalogJob($slug, 'token-1', test()->admin->id);

    app()->call([$job, 'handle']);
}

function jaStatus(string $slug): array
{
    return Setting::query()->find("themes.install.{$slug}")->value;
}

/**
 * @return list<string>
 */
function jaRequested(): array
{
    return Http::recorded()->map(fn (array $pair) => $pair[0]->url())->values()->all();
}

it('F105 AC-9: downloads the manifest and every file from the directory of the entry url and installs them', function () {
    $pkg = themePkg(['slug' => 'zz-unduh']);
    jaFake($pkg);

    jaRun('zz-unduh');

    $base = 'https://pondok.org/themes/zz-unduh/1.0.0';

    expect(jaStatus('zz-unduh')['state'])->toBe('done')
        ->and(jaRequested())->toContain("{$base}/theme.json")->toContain("{$base}/theme.css")->toContain("{$base}/fonts/zz-serif.woff2")
        ->and(themeInstalledFile('zz-unduh'))->toBe($pkg['bytes'])
        ->and((string) file_get_contents(themeInstalledSandbox()."/zz-unduh/assets/{$pkg['hash12']}/theme.css"))->toBe($pkg['files']['theme.css'])
        ->and(array_keys(themePkgEntry($pkg)))->toEqualCanonicalizing(['slug', 'name', 'description', 'version', 'schema_version', 'min_core', 'size', 'sha256', 'url', 'signature', 'key_id']);
});

it('F105 AC-9: raises the job timeout to 180 seconds', function () {
    expect((new InstallThemeFromCatalogJob('zz-unduh', 'token-1', 1))->timeout)->toBe(180);
});

dataset('failingDownloads', [
    'css berbeda dari sha256 manifest (ukuran sama)' => fn (array $pkg) => ['theme.css' => Http::response(str_replace('Tema uji', 'Tema UJI', $pkg['files']['theme.css']), 200)],
    'css lebih besar dari ukuran terdeklarasi' => fn (array $pkg) => ['theme.css' => Http::response($pkg['files']['theme.css'].str_repeat('x', 5000), 200)],
    'css lebih kecil dari ukuran terdeklarasi' => fn (array $pkg) => ['theme.css' => Http::response(substr($pkg['files']['theme.css'], 0, 100), 200)],
    'css 404' => fn (array $pkg) => ['theme.css' => Http::response('', 404)],
    'css 500' => fn (array $pkg) => ['theme.css' => Http::response('galat', 500)],
    'font 404' => fn (array $pkg) => [F105_FONT_PATH => Http::response('', 404)],
    'font bukan woff2 (ukuran sama)' => fn (array $pkg) => [F105_FONT_PATH => Http::response('wOFF'.substr($pkg['files'][F105_FONT_PATH], 4), 200)],
    'font lebih besar dari ukuran terdeklarasi' => fn (array $pkg) => [F105_FONT_PATH => Http::response($pkg['files'][F105_FONT_PATH].str_repeat('x', 5000), 200)],
    'css dialihkan ke host lain' => fn (array $pkg) => ['theme.css' => Http::response('', 302, ['Location' => 'https://evil.example/theme.css'])],
    'css dialihkan ke http' => fn (array $pkg) => ['theme.css' => Http::response('', 302, ['Location' => 'http://pondok.org/themes/x/theme.css'])],
]);

it('F105 AC-9: marks the install failed with an Indonesian message and leaves the installed theme untouched', function (Closure $overrides) {
    $old = themePkg(['slug' => 'zz-gagal', 'version' => '0.9.0']);
    themePkgInstall($old);
    $pkg = themePkg(['slug' => 'zz-gagal', 'version' => '1.0.0', 'tag' => 'baru']);
    jaFake($pkg, $overrides($pkg));
    $before = themeInstalledTree();

    jaRun('zz-gagal');

    $status = jaStatus('zz-gagal');

    expect($status['state'])->toBe('failed')
        ->and($status['message'])->toBeString()->not->toBe('')->not->toStartWith('pondok.')
        ->and(jaRequested())->toContain('https://pondok.org/themes/zz-gagal/1.0.0/theme.css')
        ->and(themeInstalledTree())->toBe($before)
        ->and(themeInstalledFile('zz-gagal'))->toBe($old['bytes']);
})->with('failingDownloads');

it('F105 AC-9: fails after the 150 second total deadline instead of installing a slow package', function () {
    $old = themePkg(['slug' => 'zz-lambat', 'version' => '0.9.0']);
    themePkgInstall($old);
    $pkg = themePkg(['slug' => 'zz-lambat', 'version' => '1.0.0', 'tag' => 'baru']);
    jaFake($pkg, ['theme.css' => function (Request $request) use ($pkg) {
        test()->travel(200)->seconds();

        return Http::response($pkg['files']['theme.css'], 200);
    }]);
    $before = themeInstalledTree();

    jaRun('zz-lambat');

    $status = jaStatus('zz-lambat');

    expect($status['state'])->toBe('failed')
        ->and($status['message'])->toBeString()->not->toBe('')->not->toStartWith('pondok.')
        ->and(jaRequested())->toContain('https://pondok.org/themes/zz-lambat/1.0.0/theme.css')
        ->and(themeInstalledTree())->toBe($before);
});

it('F105 AC-9: keeps downloading nothing extra for a theme without assets, exactly as in F103', function () {
    $package = signedThemePackage('zz-polos', '1.0.0');
    themeFakeHttp([
        THEME_CATALOG_URL => Http::response(json_encode(['themes' => [$package['entry']]]), 200),
        $package['entry']['url'] => Http::response($package['bytes'], 200),
    ]);

    jaRun('zz-polos');

    expect(jaStatus('zz-polos')['state'])->toBe('done')
        ->and(jaRequested())->toHaveCount(2)
        ->and(array_keys(themeInstalledTree()))->toBe(['zz-polos', 'zz-polos/theme.json']);
});
