<?php

use App\Actions\Konten\InstallPortalTheme;
use App\Actions\Konten\ListPortalThemes;
use App\Actions\Konten\ValidatePortalTheme;

/*
| F105 AC-2 dan AC-8 (pasang). Kontrak: `InstallPortalTheme::handle(array $entry, string $bytes, ?User $actor = null, array $files = [])`;
| `$files` = path relatif => isi byte. Tata letak terpasang: `<slug>/theme.json`, `previous.json`, `assets/<hash12>/{theme.css, fonts/*}`
| (hash12 = 12 hex pertama sha256 byte theme.json). Penolakan = `InvalidArgumentException` berpesan Indonesia tanpa mengubah folder terpasang.
| `themePkgReject`-sejenis: skema manifest asli harus lolos dulu (`iaSchemaControl`), supaya penolakan bukan karena skema `assets` belum dikenal.
*/

beforeEach(function () {
    kontenBoot();
    themeSandbox();
    themeInstalledSandbox();
    themeTestKeys(['k1', 'k2']);
    $this->admin = kontenUser('super-admin');
});

function iaSchemaControl(array $pkg): void
{
    app(ValidatePortalTheme::class)->handle("{$pkg['dir']}/theme.json");
}

/**
 * @param  array<string, mixed>  $entry
 * @param  array<string, string>  $files
 */
function iaRejected(array $entry, string $bytes, array $files, ?string $label = null): void
{
    $before = themeInstalledTree();

    themeAssertRejected(fn () => app(InstallPortalTheme::class)->handle($entry, $bytes, null, $files));

    expect(themeInstalledTree())->toBe($before, $label ?? 'folder terpasang tidak berubah');
}

function iaAssetDirs(string $slug): array
{
    return array_map('basename', glob(themeInstalledSandbox()."/{$slug}/assets/*", GLOB_ONLYDIR) ?: []);
}

function iaAssertComplete(array $pkg): void
{
    $dir = themeInstalledSandbox()."/{$pkg['slug']}/assets/{$pkg['hash12']}";

    foreach ($pkg['files'] as $path => $content) {
        expect(is_file("{$dir}/{$path}"))->toBeTrue("{$path} ada")
            ->and((string) file_get_contents("{$dir}/{$path}"))->toBe($content, "{$path} utuh");
    }
}

it('F105 AC-8: installs manifest and assets byte for byte under assets/<hash12>, with nothing else left behind', function () {
    $pkg = themePkg(['slug' => 'zz-aset']);

    themePkgInstall($pkg, $this->admin);

    $h = $pkg['hash12'];

    expect(array_keys(themeInstalledTree()))->toBe([
        'zz-aset', 'zz-aset/assets', "zz-aset/assets/{$h}", "zz-aset/assets/{$h}/fonts", "zz-aset/assets/{$h}/fonts/zz-serif.woff2",
        "zz-aset/assets/{$h}/theme.css", 'zz-aset/theme.json',
    ])
        ->and(themeInstalledFile('zz-aset'))->toBe($pkg['bytes'])
        ->and(is_dir(themeInstalledSandbox().'/_staging/zz-aset'))->toBeFalse()
        ->and(collect(app(ListPortalThemes::class)->handle())->firstWhere('slug', 'zz-aset'))->toMatchArray(['source' => 'installed', 'valid' => true]);

    iaAssertComplete($pkg);
});

it('F105 AC-2: rejects tampered, missing, extra or unsafe files and a bad signature, leaving the installed theme untouched', function () {
    $v1 = themePkg(['slug' => 'zz-aman', 'version' => '1.0.0']);
    themePkgInstall($v1, $this->admin);
    $v2 = themePkg(['slug' => 'zz-aman', 'version' => '2.0.0', 'tag' => 'v2']);
    $entry = themePkgEntry($v2);
    $good = $v2['files'];
    iaSchemaControl($v2);

    $badFont = themePkg(['slug' => 'zz-aman', 'version' => '2.0.0', 'fonts' => [['path' => F105_FONT_PATH, 'family' => F105_FONT_FAMILY, 'weight' => 400, 'style' => 'normal', 'license' => 'OFL-1.1', 'bytes' => 'wOFF'.str_repeat('a', 600)]]]);
    $badCss = themePkg(['slug' => 'zz-aman', 'version' => '2.0.0', 'extra' => '{S} .hero{color:red}']);
    $tampered = str_replace('Tema dasar uji', 'Tema dibajak', $v2['bytes']);
    iaSchemaControl($badFont);
    iaSchemaControl($badCss);

    $cases = [
        'css berubah (ukuran sama)' => [$entry, $v2['bytes'], [...$good, 'theme.css' => str_replace('Tema uji', 'Tema UJI', $good['theme.css'])]],
        'css ditambah' => [$entry, $v2['bytes'], [...$good, 'theme.css' => $good['theme.css'].' ']],
        'font berubah' => [$entry, $v2['bytes'], [...$good, F105_FONT_PATH => themePkgFontBytes('z')]],
        'css tidak dikirim' => [$entry, $v2['bytes'], array_diff_key($good, ['theme.css' => 1])],
        'font tidak dikirim' => [$entry, $v2['bytes'], array_diff_key($good, [F105_FONT_PATH => 1])],
        'tanpa berkas sama sekali' => [$entry, $v2['bytes'], []],
        'berkas php berlebih' => [$entry, $v2['bytes'], [...$good, 'evil.php' => '<?php echo 1;']],
        'font tak terdaftar berlebih' => [$entry, $v2['bytes'], [...$good, 'fonts/zz-lain.woff2' => themePkgFontBytes()]],
        'traversal pada nama berkas' => [$entry, $v2['bytes'], [...$good, '../theme.json' => 'x']],
        'traversal bersarang pada nama berkas' => [$entry, $v2['bytes'], [...$good, 'fonts/../../x.woff2' => themePkgFontBytes()]],
        'nama berkas absolut' => [$entry, $v2['bytes'], [...$good, '/tmp/x.css' => 'x']],
        'font bukan woff2 (hash cocok)' => [themePkgEntry($badFont), $badFont['bytes'], $badFont['files']],
        'css melanggar aturan (selektor kelas, hash cocok)' => [themePkgEntry($badCss), $badCss['bytes'], $badCss['files']],
        'manifest berubah satu kata setelah ditandatangani' => [[...$entry, 'size' => strlen($tampered), 'sha256' => hash('sha256', $tampered)], $tampered, $good],
        'manifest berubah, hash katalog lama' => [$entry, $tampered, $good],
    ];

    foreach ($cases as $label => [$caseEntry, $caseBytes, $caseFiles]) {
        iaRejected($caseEntry, $caseBytes, $caseFiles, $label);
    }

    expect(themeInstalledFile('zz-aman'))->toBe($v1['bytes']);
    iaAssertComplete($v1);
});

it('F105 AC-2: rejects stray files for a manifest that declares no assets', function () {
    $package = signedThemePackage('zz-polos', '1.0.0');
    iaSchemaControl(['dir' => themeTempFileDir($package['bytes'], 'zz-polos')]);

    iaRejected($package['entry'], $package['bytes'], ['theme.css' => 'body{}']);

    expect(themeInstalledTree())->toBe([]);
});

function themeTempFileDir(string $bytes, string $slug): string
{
    return dirname(themeTempFile($bytes, $slug));
}

it('F105 AC-8: keeps only the asset directories of theme.json and previous.json after each install', function () {
    $v1 = themePkg(['slug' => 'zz-pangkas', 'version' => '1.0.0']);
    $v2 = themePkg(['slug' => 'zz-pangkas', 'version' => '1.1.0', 'tag' => 'v2']);
    $v3 = themePkg(['slug' => 'zz-pangkas', 'version' => '1.2.0', 'tag' => 'v3']);

    themePkgInstall($v1);
    expect(iaAssetDirs('zz-pangkas'))->toEqualCanonicalizing([$v1['hash12']]);

    themePkgInstall($v2);
    expect(iaAssetDirs('zz-pangkas'))->toEqualCanonicalizing([$v1['hash12'], $v2['hash12']])
        ->and(themeInstalledFile('zz-pangkas', 'previous.json'))->toBe($v1['bytes']);

    themePkgInstall($v3);

    expect(iaAssetDirs('zz-pangkas'))->toEqualCanonicalizing([$v2['hash12'], $v3['hash12']])
        ->and(themeInstalledFile('zz-pangkas'))->toBe($v3['bytes'])
        ->and(themeInstalledFile('zz-pangkas', 'previous.json'))->toBe($v2['bytes']);

    iaAssertComplete($v2);
    iaAssertComplete($v3);
});

it('F105 AC-8: replaces a leftover directory of the same hash12 from a failed install instead of keeping stale files', function () {
    $pkg = themePkg(['slug' => 'zz-sisa']);
    $leftover = themeInstalledSandbox()."/zz-sisa/assets/{$pkg['hash12']}";
    mkdir("{$leftover}/fonts", 0777, true);
    file_put_contents("{$leftover}/theme.css", 'SISA RUSAK');
    file_put_contents("{$leftover}/stale.txt", 'basi');
    file_put_contents("{$leftover}/fonts/stale.woff2", 'basi');

    themePkgInstall($pkg);

    iaAssertComplete($pkg);

    expect(is_file("{$leftover}/stale.txt"))->toBeFalse()
        ->and(is_file("{$leftover}/fonts/stale.woff2"))->toBeFalse()
        ->and(themeInstalledFile('zz-sisa'))->toBe($pkg['bytes']);
});

it('F105 AC-8: keeps theme.json, its assets and previous.json intact when a write fails, and allows a retry', function (string $locked) {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        test()->markTestSkipped('Izin tulis tidak dapat dibatasi untuk root.');
    }

    $v1 = themePkg(['slug' => 'zz-gagal', 'version' => '1.0.0']);
    $v2 = themePkg(['slug' => 'zz-gagal', 'version' => '1.1.0', 'tag' => 'v2']);
    $v3 = themePkg(['slug' => 'zz-gagal', 'version' => '1.2.0', 'tag' => 'v3']);
    themePkgInstall($v1);
    themePkgInstall($v2);
    $dir = themeInstalledSandbox().'/zz-gagal'.($locked === 'assets' ? '/assets' : '');

    chmod($dir, 0555);

    try {
        expect(fn () => themePkgInstall($v3))->toThrow(InvalidArgumentException::class);
    } finally {
        chmod($dir, 0755);
    }

    expect(themeInstalledFile('zz-gagal'))->toBe($v2['bytes'])
        ->and(themeInstalledFile('zz-gagal', 'previous.json'))->toBe($v1['bytes'])
        ->and(is_file(themeInstalledSandbox().'/zz-gagal/previous.json.tmp'))->toBeFalse()
        ->and(is_dir(themeInstalledSandbox().'/_staging/zz-gagal'))->toBeFalse();

    iaAssertComplete($v1);
    iaAssertComplete($v2);

    themePkgInstall($v3);

    expect(themeInstalledFile('zz-gagal'))->toBe($v3['bytes'])
        ->and(themeInstalledFile('zz-gagal', 'previous.json'))->toBe($v2['bytes']);

    iaAssertComplete($v3);
})->with(['folder tema tidak bisa ditulis' => 'theme', 'folder assets tidak bisa ditulis' => 'assets']);

it('F105 AC-8: behaves exactly like F103 for a theme without assets, and keeps older assets only as previous', function () {
    $plain = signedThemePackage('zz-lama', '1.0.0');
    app(InstallPortalTheme::class)->handle($plain['entry'], $plain['bytes']);

    expect(array_keys(themeInstalledTree()))->toBe(['zz-lama', 'zz-lama/theme.json']);

    $withAssets = themePkg(['slug' => 'zz-lama', 'version' => '1.1.0']);
    themePkgInstall($withAssets);
    $next = signedThemePackage('zz-lama', '1.2.0');
    app(InstallPortalTheme::class)->handle($next['entry'], $next['bytes']);

    expect(themeInstalledFile('zz-lama'))->toBe($next['bytes'])
        ->and(themeInstalledFile('zz-lama', 'previous.json'))->toBe($withAssets['bytes'])
        ->and(iaAssetDirs('zz-lama'))->toBe([$withAssets['hash12']]);
});
