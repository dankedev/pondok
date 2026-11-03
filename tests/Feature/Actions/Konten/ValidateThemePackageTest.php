<?php

use App\Actions\Konten\ValidatePortalTheme;
use App\Actions\Konten\ValidateThemePackage;

/*
| F105 AC-2, AC-5, AC-7. Kontrak: `ValidateThemePackage::handle(string $dir, array $manifest): void` (lihat tests/Pest.php, `themePkg`).
| Tiap penolakan = `InvalidArgumentException` berpesan Indonesia; `themePkgReject` memastikan skema manifest lolos lebih dulu,
| jadi penolakan datang dari pemeriksaan berkas, bukan dari skema.
*/

it('F105 AC-2: accepts an intact package with css and fonts', function () {
    themePkgValidate(themePkg());

    expect(true)->toBeTrue();
});

dataset('brokenPackages', [
    'css berubah satu byte (ukuran sama)' => fn () => tap(themePkg(), fn (array $p) => file_put_contents("{$p['dir']}/theme.css", str_replace('Tema uji', 'Tema UJI', $p['files']['theme.css']))),
    'css ditambah satu byte' => fn () => tap(themePkg(), fn (array $p) => file_put_contents("{$p['dir']}/theme.css", $p['files']['theme.css'].' ')),
    'css dipotong' => fn () => tap(themePkg(), fn (array $p) => file_put_contents("{$p['dir']}/theme.css", substr($p['files']['theme.css'], 0, -5))),
    'font berubah satu byte' => fn () => tap(themePkg(), fn (array $p) => file_put_contents("{$p['dir']}/".F105_FONT_PATH, themePkgFontBytes('b'))),
    'font ditambah satu byte' => fn () => tap(themePkg(), fn (array $p) => file_put_contents("{$p['dir']}/".F105_FONT_PATH, $p['files'][F105_FONT_PATH].'x')),
    'css hilang' => fn () => tap(themePkg(), fn (array $p) => unlink("{$p['dir']}/theme.css")),
    'font hilang' => fn () => tap(themePkg(), fn (array $p) => unlink("{$p['dir']}/".F105_FONT_PATH)),
    'sha256 css di manifest salah' => fn () => themePkg(['tweak' => function (array $a) {
        $a['css']['sha256'] = str_repeat('0', 64);

        return $a;
    }]),
    'size css di manifest beda (sha benar)' => fn () => themePkg(['tweak' => function (array $a) {
        $a['css']['size']++;

        return $a;
    }]),
    'size font di manifest beda (sha benar)' => fn () => themePkg(['tweak' => function (array $a) {
        $a['fonts'][0]['size']++;

        return $a;
    }]),
    'font tak terdaftar di folder' => fn () => tap(themePkg(), fn (array $p) => file_put_contents("{$p['dir']}/fonts/zz-lain.woff2", themePkgFontBytes())),
    'berkas php di akar' => fn () => tap(themePkg(), fn (array $p) => file_put_contents("{$p['dir']}/evil.php", '<?php echo 1;')),
    'berkas cadangan css' => fn () => tap(themePkg(), fn (array $p) => file_put_contents("{$p['dir']}/theme.css.bak", 'x')),
    'berkas tersembunyi' => fn () => tap(themePkg(), fn (array $p) => file_put_contents("{$p['dir']}/.env", 'APP_KEY=x')),
    'folder bersarang di fonts' => fn () => tap(themePkg(), function (array $p) {
        mkdir("{$p['dir']}/fonts/sub");
        file_put_contents("{$p['dir']}/fonts/sub/zz-serif.woff2", themePkgFontBytes());
    }),
    'font bukan woff2 (magic wOFF, sha cocok)' => fn () => themePkg(['fonts' => [['path' => F105_FONT_PATH, 'family' => F105_FONT_FAMILY, 'weight' => 400, 'style' => 'normal', 'license' => 'OFL-1.1', 'bytes' => 'wOFF'.str_repeat('a', 600)]]]),
    'font berisi teks biasa (sha cocok)' => fn () => themePkg(['fonts' => [['path' => F105_FONT_PATH, 'family' => F105_FONT_FAMILY, 'weight' => 400, 'style' => 'normal', 'license' => 'OFL-1.1', 'bytes' => '<?php echo 1; ?>'.str_repeat('a', 600)]]]),
    'font kosong 0 byte (sha cocok, size manifest lolos skema)' => fn () => themePkg([
        'fonts' => [['path' => F105_FONT_PATH, 'family' => F105_FONT_FAMILY, 'weight' => 400, 'style' => 'normal', 'license' => 'OFL-1.1', 'bytes' => '']],
        'tweak' => function (array $a) {
            $a['fonts'][0]['size'] = 600;

            return $a;
        },
    ]),
    'css berupa symlink (isi sama)' => fn () => tap(themePkg(), function (array $p) {
        file_put_contents("{$p['root']}/luar.css", $p['files']['theme.css']);
        unlink("{$p['dir']}/theme.css");
        symlink("{$p['root']}/luar.css", "{$p['dir']}/theme.css");
    }),
    'font berupa symlink (isi sama)' => fn () => tap(themePkg(), function (array $p) {
        file_put_contents("{$p['root']}/luar.woff2", $p['files'][F105_FONT_PATH]);
        unlink("{$p['dir']}/".F105_FONT_PATH);
        symlink("{$p['root']}/luar.woff2", "{$p['dir']}/".F105_FONT_PATH);
    }),
    'folder fonts berupa symlink' => fn () => tap(themePkg(), function (array $p) {
        rename("{$p['dir']}/fonts", "{$p['root']}/luar-fonts");
        symlink("{$p['root']}/luar-fonts", "{$p['dir']}/fonts");
    }),
]);

it('F105 AC-2: rejects a package whose files do not match the manifest or are unsafe', function (Closure $build) {
    // Kontrol: paket dasar yang sama lolos, sehingga hanya perbedaan di dataset yang menjadi sebabnya.
    themePkgValidate(themePkg());

    $pkg = $build();

    themePkgReject($pkg);
})->with('brokenPackages');

dataset('badFontRules', [
    'tanpa font-display' => ['fontFace' => '@font-face{font-family:"Zz Uji Serif";src:url("fonts/zz-serif.woff2") format("woff2");font-weight:400;font-style:normal}'],
    'font-display block' => ['fontFace' => '@font-face{font-family:"Zz Uji Serif";src:url("fonts/zz-serif.woff2") format("woff2");font-weight:400;font-style:normal;font-display:block}'],
    'font-display fallback' => ['fontFace' => '@font-face{font-family:"Zz Uji Serif";src:url("fonts/zz-serif.woff2") format("woff2");font-display:fallback}'],
    'family beda dari manifest' => ['fontFace' => '@font-face{font-family:"Nama Lain";src:url("fonts/zz-serif.woff2") format("woff2");font-display:swap}'],
    'src dari CDN' => ['fontFace' => '@font-face{font-family:"Zz Uji Serif";src:url("https://cdn.example/f.woff2") format("woff2");font-display:swap}'],
    'src protocol-relative' => ['fontFace' => '@font-face{font-family:"Zz Uji Serif";src:url("//cdn.example/f.woff2") format("woff2");font-display:swap}'],
    'src local()' => ['fontFace' => '@font-face{font-family:"Zz Uji Serif";src:local("Zz Uji Serif");font-display:swap}'],
    'src local() ditambah url sah' => ['fontFace' => '@font-face{font-family:"Zz Uji Serif";src:local("Arial"),url("fonts/zz-serif.woff2") format("woff2");font-display:swap}'],
    'src path di luar manifest' => ['fontFace' => '@font-face{font-family:"Zz Uji Serif";src:url("fonts/lain.woff2") format("woff2");font-display:swap}'],
    'src path traversal' => ['fontFace' => '@font-face{font-family:"Zz Uji Serif";src:url("fonts/../theme.json") format("woff2");font-display:swap}'],
    'src data URI' => ['fontFace' => '@font-face{font-family:"Zz Uji Serif";src:url("data:font/woff2;base64,AAAA") format("woff2");font-display:swap}'],
    'src tanpa format' => ['fontFace' => '@font-face{font-family:"Zz Uji Serif";src:url("fonts/zz-serif.woff2");font-display:swap}'],
    'src format woff' => ['fontFace' => '@font-face{font-family:"Zz Uji Serif";src:url("fonts/zz-serif.woff2") format("woff");font-display:swap}'],
    'src kedua format woff' => ['fontFace' => '@font-face{font-family:"Zz Uji Serif";src:url("fonts/zz-serif.woff2") format("woff2"),url("fonts/zz-serif.woff2") format("woff");font-display:swap}'],
    'font di manifest tak dirujuk @font-face' => ['fontFace' => ''],
    '@font-face kedua untuk family tak terdaftar' => ['fontFace' => '@font-face{font-family:"Zz Uji Serif";src:url("fonts/zz-serif.woff2") format("woff2");font-display:swap}@font-face{font-family:"Tak Terdaftar";src:url("fonts/zz-serif.woff2") format("woff2");font-display:swap}'],
]);

it('F105 AC-5: rejects an @font-face that breaks the font rules', function (string $fontFace) {
    themePkgValidate(themePkg());

    themePkgReject(themePkg(['fontFace' => $fontFace]));
})->with('badFontRules');

it('F105 AC-5: rejects an @font-face when the manifest declares no fonts', function () {
    themePkgValidate(themePkg());

    themePkgReject(themePkg(['fonts' => []]));
});

it('F105 AC-5: rejects a font that overrides a core font family', function (string $family) {
    $font = ['path' => F105_FONT_PATH, 'family' => $family, 'weight' => 400, 'style' => 'normal', 'license' => 'OFL-1.1', 'bytes' => themePkgFontBytes()];

    themePkgReject(themePkg(['fonts' => [$font], 'fontFace' => themePkgFontFace(['family' => $family])]));
})->with(['Plus Jakarta Sans', 'Lora', 'Nunito', 'Noto Naskh Arabic']);

dataset('badContrast', [
    'rasio di bawah 4,5 untuk normal' => [[['fg' => '--pt-fg', 'bg' => '--pt-bg', 'size' => 'normal']], '--pt-fg:#949494;--pt-bg:#ffffff'],
    'rasio di bawah 3 untuk large' => [[['fg' => '--pt-fg', 'bg' => '--pt-bg', 'size' => 'large']], '--pt-fg:#aaaaaa;--pt-bg:#ffffff'],
]);

it('F105 AC-7: accepts a pair at or above its threshold (4.5:1 normal, 3:1 large)', function () {
    // #767676 di atas putih = 4,54:1 (lolos normal); #949494 di atas putih = 3,03:1 (lolos large, gagal normal).
    foreach ([['#767676', 'normal'], ['#949494', 'large'], ['#000000', 'normal']] as [$fg, $size]) {
        $pkg = themePkg(['contrast' => [['fg' => '--pt-fg', 'bg' => '--pt-bg', 'size' => $size]]]);
        file_put_contents("{$pkg['dir']}/theme.css", str_replace('--pt-fg:#1a2b1c;--pt-bg:#fbf7ee', "--pt-fg:{$fg};--pt-bg:#ffffff", $pkg['files']['theme.css']));

        themePkgValidate(themePkgRehash($pkg));
    }

    expect(true)->toBeTrue();
});

/**
 * Hitung ulang sha256/size css di manifest setelah test mengubah `theme.css` langsung.
 *
 * @param  array<string, mixed>  $pkg
 * @return array<string, mixed>
 */
function themePkgRehash(array $pkg): array
{
    $css = (string) file_get_contents("{$pkg['dir']}/theme.css");
    $manifest = $pkg['manifest'];
    $manifest['assets']['css'] = ['path' => 'theme.css', 'sha256' => hash('sha256', $css), 'size' => strlen($css)];
    file_put_contents("{$pkg['dir']}/theme.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

    return [...$pkg, 'manifest' => $manifest];
}

it('F105 AC-7: rejects a pair below its threshold and names the pair and the ratio', function (array $contrast, string $newDefinition) {
    $pkg = themePkg(['contrast' => $contrast]);
    file_put_contents("{$pkg['dir']}/theme.css", str_replace('--pt-fg:#1a2b1c;--pt-bg:#fbf7ee', $newDefinition, $pkg['files']['theme.css']));
    $pkg = themePkgRehash($pkg);

    $manifest = app(ValidatePortalTheme::class)->handle("{$pkg['dir']}/theme.json");

    try {
        app(ValidateThemePackage::class)->handle($pkg['dir'], $manifest);
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('--pt-fg')->toContain('--pt-bg')->toMatch('/\d[.,]\d/');

        return;
    }

    test()->fail('InvalidArgumentException diharapkan.');
})->with('badContrast');

dataset('brokenContrastDefinitions', [
    'variabel tidak ada' => fn (string $s) => str_replace('--pt-bg:#fbf7ee;', '', $s),
    'bukan hex literal (rgb)' => fn (string $s) => str_replace('--pt-bg:#fbf7ee', '--pt-bg:rgb(251,247,238)', $s),
    'bukan hex literal (nama warna)' => fn (string $s) => str_replace('--pt-bg:#fbf7ee', '--pt-bg:white', $s),
    'hex pendek' => fn (string $s) => str_replace('--pt-bg:#fbf7ee', '--pt-bg:#fff', $s),
    'hex dengan alpha' => fn (string $s) => str_replace('--pt-bg:#fbf7ee', '--pt-bg:#fbf7eeff', $s),
    'merujuk var() lain' => fn (string $s) => str_replace('--pt-bg:#fbf7ee', '--pt-bg:var(--pt-accent)', $s),
    'didefinisikan ganda di blok scope' => fn (string $s) => str_replace('--pt-bg:#fbf7ee', '--pt-bg:#fbf7ee;--pt-bg:#ffffff', $s),
    'didefinisikan ganda di dua blok scope' => fn (string $s) => $s."\nbody[data-pt-theme=\"{SLUG}\"]{--pt-bg:#ffffff}",
    'didefinisikan hanya di dalam komponen' => fn (string $s) => str_replace('--pt-bg:#fbf7ee;', '', $s)."\nbody[data-pt-theme=\"{SLUG}\"] [data-pt=\"hero\"]{--pt-bg:#fbf7ee}",
    'didefinisikan hanya di dalam @media' => fn (string $s) => str_replace('--pt-bg:#fbf7ee;', '', $s)."\n@media (min-width:1px){body[data-pt-theme=\"{SLUG}\"]{--pt-bg:#fbf7ee}}",
]);

it('F105 AC-7: rejects a contrast pair whose custom property is missing, not a literal #rrggbb, or not defined exactly once at the scope', function (Closure $break) {
    themePkgValidate(themePkg());

    $pkg = themePkg();
    $css = $break($pkg['files']['theme.css']);
    file_put_contents("{$pkg['dir']}/theme.css", str_replace('{SLUG}', $pkg['slug'], $css));

    themePkgReject(themePkgRehash($pkg));
})->with('brokenContrastDefinitions');
