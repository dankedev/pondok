<?php

use App\Actions\Konten\ValidatePortalTheme;

/*
| F105 AC-1. Skema kunci puncak opsional `assets` (set kunci tertutup) pada `theme.json`: `css` = {path, sha256, size}; `fonts` = maks. 6
| {path, family, weight (integer), style, license, sha256, size}; `contrast` = daftar {fg, bg, size}. Pemeriksaan berkas ada di
| `ValidateThemePackage` (ValidateThemePackageTest). `ValidatePortalTheme::handle` menambah `assets` ke keluaran HANYA bila ada.
*/

function assetsManifest(?Closure $tweak = null): array
{
    $pkg = themePkg();
    $assets = $pkg['manifest']['assets'];
    $assets = $tweak ? $tweak($assets) : $assets;

    return [...$pkg['manifest'], 'assets' => $assets];
}

function assetsRejection(array $manifest): ?string
{
    try {
        app(ValidatePortalTheme::class)->handle(themeTempFile($manifest, $manifest['slug']));
    } catch (InvalidArgumentException $e) {
        return $e->getMessage() === '' ? '(tanpa pesan)' : $e->getMessage();
    }

    return null;
}

function assetsFont(int $n, array $overrides = []): array
{
    return [
        'path' => "fonts/zz-f{$n}.woff2", 'family' => "Zz Font {$n}", 'weight' => 400, 'style' => 'normal', 'license' => 'OFL-1.1',
        'sha256' => str_repeat('a', 64), 'size' => 1000, ...$overrides,
    ];
}

it('F105 AC-1: accepts a valid assets block and returns it in the normalised tree', function () {
    $manifest = assetsManifest();

    $tree = app(ValidatePortalTheme::class)->handle(themeTempFile($manifest, $manifest['slug']));

    expect($tree['assets'])->toBe($manifest['assets'])
        ->and($tree['assets']['css']['path'])->toBe('theme.css');
});

it('F105 AC-1: leaves a theme without assets exactly as before, with no assets key in the output', function () {
    $manifest = themeBaseManifest();

    $tree = app(ValidatePortalTheme::class)->handle(themeTempFile($manifest));

    expect(array_key_exists('assets', $tree))->toBeFalse();
});

it('F105 AC-1: accepts the three allowed licences, up to six fonts, and the size boundaries', function () {
    foreach (['OFL-1.1', 'Apache-2.0', 'CC0-1.0'] as $licence) {
        expect(assetsRejection(assetsManifest(fn (array $a) => [...$a, 'fonts' => [assetsFont(1, ['license' => $licence])]])))->toBeNull($licence);
    }

    $six = array_map(fn (int $n) => assetsFont($n, ['size' => 100000]), range(1, 6));
    $limits = assetsManifest(function (array $a) {
        $a['css']['size'] = 128 * 1024;
        $a['fonts'] = [assetsFont(1, ['size' => 250 * 1024])];

        return $a;
    });

    expect(assetsRejection(assetsManifest(fn (array $a) => [...$a, 'fonts' => $six])))->toBeNull('enam font')
        ->and(assetsRejection($limits))->toBeNull('batas persis: css 128 KB dan font 250 KB')
        ->and(assetsRejection(assetsManifest(fn (array $a) => [...$a, 'fonts' => []])))->toBeNull('tanpa font');
});

dataset('rejectedAssets', [
    'kunci asing di assets' => fn (array $a) => [...$a, 'js' => ['path' => 'theme.js']],
    'kunci asing di css' => function (array $a) {
        $a['css']['extra'] = 1;

        return $a;
    },
    'kunci asing di font' => fn (array $a) => [...$a, 'fonts' => [assetsFont(1, ['url' => 'https://cdn.example/f.woff2'])]],
    'kunci asing di contrast' => function (array $a) {
        $a['contrast'][0]['note'] = 'x';

        return $a;
    },
    'assets bukan objek' => fn (array $a) => ['theme.css'],
    'css bukan objek' => fn (array $a) => [...$a, 'css' => 'theme.css'],
    'css path lain' => function (array $a) {
        $a['css']['path'] = 'style.css';

        return $a;
    },
    'css path traversal' => function (array $a) {
        $a['css']['path'] = '../theme.css';

        return $a;
    },
    'css path huruf besar' => function (array $a) {
        $a['css']['path'] = 'Theme.css';

        return $a;
    },
    'css path di subfolder' => function (array $a) {
        $a['css']['path'] = 'fonts/theme.css';

        return $a;
    },
    'font path bukan woff2' => fn (array $a) => [...$a, 'fonts' => [assetsFont(1, ['path' => 'fonts/zz-f1.woff'])]],
    'font path huruf besar' => fn (array $a) => [...$a, 'fonts' => [assetsFont(1, ['path' => 'fonts/Zz-F1.woff2'])]],
    'font path subfolder' => fn (array $a) => [...$a, 'fonts' => [assetsFont(1, ['path' => 'fonts/sub/zz-f1.woff2'])]],
    'font path traversal' => fn (array $a) => [...$a, 'fonts' => [assetsFont(1, ['path' => 'fonts/../zz-f1.woff2'])]],
    'font path absolut' => fn (array $a) => [...$a, 'fonts' => [assetsFont(1, ['path' => '/fonts/zz-f1.woff2'])]],
    'font path diawali tanda hubung' => fn (array $a) => [...$a, 'fonts' => [assetsFont(1, ['path' => 'fonts/-f1.woff2'])]],
    'font path ganda' => fn (array $a) => [...$a, 'fonts' => [assetsFont(1), assetsFont(2, ['path' => 'fonts/zz-f1.woff2'])]],
    'tujuh font' => fn (array $a) => [...$a, 'fonts' => array_map(fn (int $n) => assetsFont($n), range(1, 7))],
    'lisensi MIT' => fn (array $a) => [...$a, 'fonts' => [assetsFont(1, ['license' => 'MIT'])]],
    'lisensi huruf kecil' => fn (array $a) => [...$a, 'fonts' => [assetsFont(1, ['license' => 'ofl-1.1'])]],
    'lisensi kosong' => fn (array $a) => [...$a, 'fonts' => [assetsFont(1, ['license' => ''])]],
    'font style tak dikenal' => fn (array $a) => [...$a, 'fonts' => [assetsFont(1, ['style' => 'bold'])]],
    'sha256 pendek' => fn (array $a) => [...$a, 'fonts' => [assetsFont(1, ['sha256' => str_repeat('a', 63)])]],
    'sha256 bukan hex' => fn (array $a) => [...$a, 'fonts' => [assetsFont(1, ['sha256' => str_repeat('g', 64)])]],
    'sha256 css bukan hex' => function (array $a) {
        $a['css']['sha256'] = 'xyz';

        return $a;
    },
    'ukuran font di atas 250 KB' => fn (array $a) => [...$a, 'fonts' => [assetsFont(1, ['size' => 250 * 1024 + 1])]],
    'ukuran css di atas 128 KB' => function (array $a) {
        $a['css']['size'] = 128 * 1024 + 1;

        return $a;
    },
    'ukuran nol' => fn (array $a) => [...$a, 'fonts' => [assetsFont(1, ['size' => 0])]],
    'ukuran negatif' => fn (array $a) => [...$a, 'fonts' => [assetsFont(1, ['size' => -5])]],
    'ukuran string' => fn (array $a) => [...$a, 'fonts' => [assetsFont(1, ['size' => '1000'])]],
    'ukuran pecahan' => fn (array $a) => [...$a, 'fonts' => [assetsFont(1, ['size' => 1000.5])]],
    'total paket di atas 1,5 MB' => function (array $a) {
        $a['css']['size'] = 100000;
        $a['fonts'] = array_map(fn (int $n) => assetsFont($n, ['size' => 250 * 1024]), range(1, 6));

        return $a;
    },
    'css tanpa contrast' => function (array $a) {
        unset($a['contrast']);

        return $a;
    },
    'css dengan contrast kosong' => fn (array $a) => [...$a, 'contrast' => []],
    'contrast fg bukan custom property' => function (array $a) {
        $a['contrast'][0]['fg'] = 'red';

        return $a;
    },
    'contrast bg tanpa nama' => function (array $a) {
        $a['contrast'][0]['bg'] = '--';

        return $a;
    },
    'contrast size tak dikenal' => function (array $a) {
        $a['contrast'][0]['size'] = 'huge';

        return $a;
    },
    'contrast tanpa size' => function (array $a) {
        unset($a['contrast'][0]['size']);

        return $a;
    },
]);

it('F105 AC-1: rejects an invalid assets block', function (Closure $mutate) {
    $manifest = assetsManifest($mutate);

    // Kontrol: manifest dasar yang sama tanpa mutasi lolos, jadi penolakan benar-benar karena mutasi.
    expect(assetsRejection(assetsManifest()))->toBeNull()
        ->and(assetsRejection($manifest))->not->toBeNull()
        ->and(assetsRejection($manifest))->not->toStartWith('pondok.');
})->with('rejectedAssets');

it('F105 AC-1: raises the core version to 1.2.0 and rejects min_core 1.3.0', function () {
    $manifest = assetsManifest();

    expect(ValidatePortalTheme::CORE_VERSION)->toBe('1.2.0')
        ->and(assetsRejection($manifest))->toBeNull()
        ->and(assetsRejection([...$manifest, 'min_core' => '1.3.0']))->not->toBeNull();
});

it('F105 AC-1: keeps css and fonts out of the root key set', function () {
    expect(assetsRejection([...assetsManifest(), 'css' => 'body{}']))->not->toBeNull()
        ->and(assetsRejection([...themeBaseManifest(), 'fonts' => []]))->not->toBeNull();
});
