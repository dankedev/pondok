<?php

use App\Actions\Konten\InstallPortalTheme;
use App\Actions\Konten\RemovePortalTheme;
use App\Actions\Konten\RollbackPortalTheme;
use Illuminate\Support\Facades\File;

/*
| F105 AC-8 (kembalikan dan hapus). Rollback memvalidasi `previous.json` lewat staging, memeriksa dir asetnya (ada dan size cocok; bila tidak,
| ditolak tanpa perubahan), mengembalikan manifest + aset, lalu memangkas dir selain milik `theme.json` baru. Remove menghapus semua.
*/

beforeEach(function () {
    kontenBoot();
    themeSandbox();
    themeInstalledSandbox();
    themeTestKeys(['k1', 'k2']);
    $this->admin = kontenUser('super-admin');
});

function raDirs(string $slug): array
{
    return array_map('basename', glob(themeInstalledSandbox()."/{$slug}/assets/*", GLOB_ONLYDIR) ?: []);
}

function raTwoVersions(string $slug): array
{
    $v1 = themePkg(['slug' => $slug, 'version' => '1.0.0']);
    $v2 = themePkg(['slug' => $slug, 'version' => '1.1.0', 'tag' => 'v2']);
    themePkgInstall($v1);
    themePkgInstall($v2);

    return [$v1, $v2];
}

it('F105 AC-8: rolls back manifest and assets together and prunes the directory of the rolled-back version', function () {
    [$v1, $v2] = raTwoVersions('zz-balik');

    app(RollbackPortalTheme::class)->handle('zz-balik', $this->admin);

    $h1 = $v1['hash12'];

    expect(themeInstalledFile('zz-balik'))->toBe($v1['bytes'])
        ->and(themeInstalledFile('zz-balik', 'previous.json'))->toBeNull()
        ->and(raDirs('zz-balik'))->toBe([$h1])
        ->and(array_keys(themeInstalledTree()))->toBe([
            'zz-balik', 'zz-balik/assets', "zz-balik/assets/{$h1}", "zz-balik/assets/{$h1}/fonts", "zz-balik/assets/{$h1}/fonts/zz-serif.woff2",
            "zz-balik/assets/{$h1}/theme.css", 'zz-balik/theme.json',
        ])
        ->and((string) file_get_contents(themeInstalledSandbox()."/zz-balik/assets/{$h1}/theme.css"))->toBe($v1['files']['theme.css'])
        ->and($v2['hash12'])->not->toBe($h1);
});

it('F105 AC-8: refuses a rollback when the asset directory of previous.json is missing or damaged, changing nothing', function (Closure $damage) {
    [$v1, $v2] = raTwoVersions('zz-rusak');
    $damage(themeInstalledSandbox()."/zz-rusak/assets/{$v1['hash12']}");
    $before = themeInstalledTree();

    themeAssertRejected(fn () => app(RollbackPortalTheme::class)->handle('zz-rusak', $this->admin));

    expect(themeInstalledTree())->toBe($before)
        ->and(themeInstalledFile('zz-rusak'))->toBe($v2['bytes'])
        ->and(themeInstalledFile('zz-rusak', 'previous.json'))->toBe($v1['bytes'])
        ->and(kontenActivities()->where('description', 'portal_theme_rolled_back'))->toHaveCount(0);
})->with([
    'dir aset hilang' => fn (string $dir) => File::deleteDirectory($dir),
    'css hilang' => fn (string $dir) => unlink("{$dir}/theme.css"),
    'font hilang' => fn (string $dir) => unlink("{$dir}/".F105_FONT_PATH),
    'css ukuran beda' => fn (string $dir) => file_put_contents("{$dir}/theme.css", 'terpotong'),
    'font ukuran beda' => fn (string $dir) => file_put_contents("{$dir}/".F105_FONT_PATH, 'wOF2'),
]);

it('F105 AC-8: rolls back to a version without assets and prunes the assets of the newer one', function () {
    $plain = signedThemePackage('zz-polos', '1.0.0');
    app(InstallPortalTheme::class)->handle($plain['entry'], $plain['bytes']);
    $withAssets = themePkg(['slug' => 'zz-polos', 'version' => '1.1.0']);
    themePkgInstall($withAssets);

    app(RollbackPortalTheme::class)->handle('zz-polos', $this->admin);

    expect(themeInstalledFile('zz-polos'))->toBe($plain['bytes'])
        ->and(raDirs('zz-polos'))->toBe([]);
});

it('F105 AC-8: removes the whole theme folder including assets, and leaves other themes untouched', function () {
    raTwoVersions('zz-hapus');
    [$keep] = raTwoVersions('zz-tetap');
    $keepTree = array_filter(themeInstalledTree(), fn ($v, string $path) => str_starts_with($path, 'zz-tetap'), ARRAY_FILTER_USE_BOTH);

    app(RemovePortalTheme::class)->handle('zz-hapus', $this->admin);

    $after = themeInstalledTree();

    expect(is_dir(themeInstalledSandbox().'/zz-hapus'))->toBeFalse()
        ->and(array_filter(array_keys($after), fn (string $path) => str_starts_with($path, 'zz-hapus')))->toBe([])
        ->and($after)->toBe($keepTree)
        ->and(themeInstalledFile('zz-tetap'))->not->toBeNull()
        ->and($keep['slug'])->toBe('zz-tetap');
});
