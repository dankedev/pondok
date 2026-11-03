<?php

use App\Actions\Konten\InstallPortalTheme;
use App\Actions\Konten\ValidatePortalTheme;
use Illuminate\Support\Facades\Artisan;

/*
| F105 AC-14. `pondok:theme-sign <folder>` (folder paket: theme.json + theme.css + fonts/) menjalankan `ValidateThemePackage` yang sama dengan pemasangan dan gagal
| (exit 1, pesan Indonesia jelas, tanpa entri) pada tiap pelanggaran; hash tak cocok mencetak nilai yang benar; `--write-hashes` menulis ulang sha256/size di theme.json
| sebelum menandatangani. Keluaran entri sama dengan F103 dan deterministik. Tiap kasus gagal didahului kontrol skema supaya bukan karena skema `assets` belum dikenal.
*/

beforeEach(function () {
    kontenBoot();
    themeInstalledSandbox();
    $this->keys = themeTestKeys(['k1', 'k2']);
});

/**
 * @param  array<string, mixed>  $pkg
 * @param  array<string, mixed>  $options
 * @return array{exit: int, output: string}
 */
function tsaSign(array $pkg, array $options = []): array
{
    file_put_contents($secret = "{$pkg['root']}/rahasia.key", base64_encode(test()->keys['k1']['secret']));
    $exit = Artisan::call('pondok:theme-sign', [
        'path' => $pkg['dir'], '--secret-file' => $secret, '--key-id' => 'k1',
        '--url' => "https://pondok.org/themes/{$pkg['slug']}/{$pkg['version']}/theme.json", ...$options,
    ]);

    return ['exit' => $exit, 'output' => Artisan::output()];
}

it('F105 AC-14: signs a package folder into the same catalog entry shape as F103, which installs with its files', function () {
    $pkg = themePkg(['slug' => 'zz-penerbit']);

    $run = tsaSign($pkg);
    $entry = json_decode(trim($run['output']), true);

    expect($run['exit'])->toBe(0)
        ->and($entry)->toBeArray()
        ->and(array_keys($entry))->toEqualCanonicalizing(['slug', 'name', 'description', 'version', 'schema_version', 'min_core', 'size', 'sha256', 'url', 'signature', 'key_id'])
        ->and($entry)->toMatchArray(['slug' => 'zz-penerbit', 'version' => '1.0.0', 'min_core' => '1.2.0', 'size' => strlen($pkg['bytes']), 'sha256' => hash('sha256', $pkg['bytes']), 'key_id' => 'k1'])
        ->and(sodium_crypto_sign_verify_detached(base64_decode($entry['signature']), $pkg['bytes'], base64_decode(config('pondok.theme_public_keys')['k1'])))->toBeTrue();

    app(InstallPortalTheme::class)->handle($entry, $pkg['bytes'], null, $pkg['files']);

    expect(themeInstalledFile('zz-penerbit'))->toBe($pkg['bytes']);
});

it('F105 AC-14: prints identical output on two runs', function () {
    $pkg = themePkg();

    $first = tsaSign($pkg);
    $second = tsaSign($pkg);

    expect($first['exit'])->toBe(0)->and($second['output'])->toBe($first['output']);
});

dataset('unsignablePackages', [
    'css berubah' => fn () => tap(themePkg(), fn (array $p) => file_put_contents("{$p['dir']}/theme.css", str_replace('Tema uji', 'Tema UJI', $p['files']['theme.css']))),
    'font berubah' => fn () => tap(themePkg(), fn (array $p) => file_put_contents("{$p['dir']}/".F105_FONT_PATH, themePkgFontBytes('b'))),
    'css hilang' => fn () => tap(themePkg(), fn (array $p) => unlink("{$p['dir']}/theme.css")),
    'font hilang' => fn () => tap(themePkg(), fn (array $p) => unlink("{$p['dir']}/".F105_FONT_PATH)),
    'berkas berlebih' => fn () => tap(themePkg(), fn (array $p) => file_put_contents("{$p['dir']}/evil.php", '<?php')),
    'font bukan woff2' => fn () => themePkg(['fonts' => [['path' => F105_FONT_PATH, 'family' => F105_FONT_FAMILY, 'weight' => 400, 'style' => 'normal', 'license' => 'OFL-1.1', 'bytes' => 'wOFF'.str_repeat('a', 600)]]]),
    'css dengan selektor kelas' => fn () => themePkg(['extra' => '{S} .hero{color:red}']),
    'css dengan url() eksternal' => fn () => themePkg(['extra' => '{S}{background-image:url("https://evil.example/x.png")}']),
    'css dengan animasi di luar no-preference' => fn () => themePkg(['extra' => '{S} a{animation:pt-rise 1s}']),
    'font-face tanpa font-display swap' => fn () => themePkg(['fontFace' => '@font-face{font-family:"Zz Uji Serif";src:url("fonts/zz-serif.woff2") format("woff2")}']),
    'kontras gagal' => fn () => tap(themePkg(), function (array $p) {
        $css = str_replace('--pt-fg:#1a2b1c;--pt-bg:#fbf7ee', '--pt-fg:#aaaaaa;--pt-bg:#ffffff', $p['files']['theme.css']);
        file_put_contents("{$p['dir']}/theme.css", $css);
        $manifest = $p['manifest'];
        $manifest['assets']['css'] = ['path' => 'theme.css', 'sha256' => hash('sha256', $css), 'size' => strlen($css)];
        file_put_contents("{$p['dir']}/theme.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }),
    'css berupa symlink' => fn () => tap(themePkg(), function (array $p) {
        file_put_contents("{$p['root']}/luar.css", $p['files']['theme.css']);
        unlink("{$p['dir']}/theme.css");
        symlink("{$p['root']}/luar.css", "{$p['dir']}/theme.css");
    }),
]);

it('F105 AC-14: fails with exit 1, a clear Indonesian message and no entry for each violation', function (Closure $build) {
    $pkg = $build();
    app(ValidatePortalTheme::class)->handle("{$pkg['dir']}/theme.json");

    $run = tsaSign($pkg);

    expect($run['exit'])->toBe(1)
        ->and(trim($run['output']))->not->toBe('')
        ->and($run['output'])->not->toContain('"signature"')
        ->and($run['output'])->not->toContain('pondok.theme')
        ->and(strtolower($run['output']))->not->toContain('sodium')->not->toContain('exception')
        ->and($run['output'])->not->toContain('#0 ');
})->with('unsignablePackages');

it('F105 AC-14: prints the correct hash and size when a file does not match the manifest', function () {
    $pkg = themePkg();
    file_put_contents("{$pkg['dir']}/theme.css", $changed = str_replace('Tema uji', 'Tema UJI', $pkg['files']['theme.css']));
    file_put_contents("{$pkg['dir']}/".F105_FONT_PATH, $font = themePkgFontBytes('c'));
    app(ValidatePortalTheme::class)->handle("{$pkg['dir']}/theme.json");

    $run = tsaSign($pkg);

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->toContain(hash('sha256', $changed))
        ->and($run['output'])->toContain(hash('sha256', $font));
});

it('F105 AC-14: --write-hashes rewrites sha256 and size in theme.json before signing, and the result verifies and installs', function () {
    $pkg = themePkg(['slug' => 'zz-tulis']);
    file_put_contents("{$pkg['dir']}/theme.css", $css = $pkg['files']['theme.css']."\n/* disunting */");
    file_put_contents("{$pkg['dir']}/".F105_FONT_PATH, $font = 'wOF2'.str_repeat('z', 900));
    app(ValidatePortalTheme::class)->handle("{$pkg['dir']}/theme.json");

    expect(tsaSign($pkg)['exit'])->toBe(1);

    $run = tsaSign($pkg, ['--write-hashes' => true]);
    $entry = json_decode(trim($run['output']), true);
    $written = (string) file_get_contents("{$pkg['dir']}/theme.json");
    $manifest = json_decode($written, true);

    expect($run['exit'])->toBe(0)
        ->and($manifest['assets']['css'])->toMatchArray(['sha256' => hash('sha256', $css), 'size' => strlen($css)])
        ->and($manifest['assets']['fonts'][0])->toMatchArray(['sha256' => hash('sha256', $font), 'size' => strlen($font)])
        ->and($entry['sha256'])->toBe(hash('sha256', $written))
        ->and($entry['size'])->toBe(strlen($written))
        ->and(sodium_crypto_sign_verify_detached(base64_decode($entry['signature']), $written, base64_decode(config('pondok.theme_public_keys')['k1'])))->toBeTrue()
        ->and(tsaSign($pkg)['output'])->toBe($run['output'], 'tanpa --write-hashes lagi, hasil sama');

    app(InstallPortalTheme::class)->handle($entry, $written, null, ['theme.css' => $css, F105_FONT_PATH => $font]);

    expect(themeInstalledFile('zz-tulis'))->toBe($written);
});

it('F105 AC-14: --write-hashes does not rescue a package that breaks the css rules or lacks a file, and leaves theme.json untouched', function (Closure $build) {
    $pkg = $build();
    app(ValidatePortalTheme::class)->handle("{$pkg['dir']}/theme.json");
    $before = md5_file("{$pkg['dir']}/theme.json");

    $run = tsaSign($pkg, ['--write-hashes' => true]);

    expect($run['exit'])->toBe(1)
        ->and($run['output'])->not->toContain('"signature"')
        ->and(md5_file("{$pkg['dir']}/theme.json"))->toBe($before);
})->with([
    'selektor kelas' => fn () => themePkg(['extra' => '{S} .hero{color:red}']),
    'css hilang' => fn () => tap(themePkg(), fn (array $p) => unlink("{$p['dir']}/theme.css")),
    'berkas berlebih' => fn () => tap(themePkg(), fn (array $p) => file_put_contents("{$p['dir']}/evil.php", '<?php')),
]);
