<?php

use App\Actions\Konten\DuplicatePortalTheme;
use App\Actions\Konten\ValidatePortalTheme;
use App\Actions\Konten\ValidateThemePackage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/*
| F111 PR5, VERIFY adversarial untuk `pondok:theme-export` (AC-11): celah yang dicurigai di luar test RED
| (`ThemeExportCommandTest`): validasi `--as`/`source`, folder tujuan aneh, ketepatan ganti scope CSS, isi paket, dan
| kegagalan di tengah tulis.
*/

beforeEach(function () {
    kontenBoot();
    themeSandbox();
    themeInstalledSandbox();
    $this->admin = kontenUser('super-admin');
});

/** @return array{root: string, folder: string} */
function exvTarget(): array
{
    $root = sys_get_temp_dir().'/pondok-f111-adv-'.Str::random(10);
    mkdir($root, 0777, true);
    $GLOBALS['themePkgRoots'][] = $root;

    return ['root' => $root, 'folder' => "{$root}/keluar"];
}

/** @return array<string, string> */
function exvTree(string $root): array
{
    $tree = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $path => $info) {
        $tree[substr((string) $path, strlen($root) + 1)] = $info->isLink() ? 'link:'.readlink((string) $path) : ($info->isDir() ? 'dir' : (string) md5_file((string) $path));
    }

    ksort($tree);

    return $tree;
}

/** @return array{exit: int, output: string} */
function exvRun(string $source, string $folder, ?string $as): array
{
    $exit = Artisan::call('pondok:theme-export', ['source' => $source, 'folder' => $folder, ...($as === null ? [] : ['--as' => $as])]);

    return ['exit' => $exit, 'output' => Artisan::output()];
}

/** Sumber lokal ber-`assets`. `$options` diteruskan ke `themePkg` (slug sumber resmi `zz`, tema lokal `lokal-ab`). */
function exvLocal(array $options = []): string
{
    themePkgPlace(themePkg(['slug' => 'zz', ...$options]));

    return app(DuplicatePortalTheme::class)->handle('zz', ['name' => 'Ab'], test()->admin);
}

/**
 * Jalankan ekspor yang harus gagal bersih: exit 1, pesan Indonesia, pohon tujuan (induk `$watch`) dan seluruh tema terpasang tidak berubah.
 */
function exvAssertRejected(string $source, string $folder, ?string $as, ?string $watch = null): void
{
    $watch ??= dirname($folder);
    $installed = themeInstalledBaseTree();
    $before = is_dir($watch) ? exvTree($watch) : null;

    $run = exvRun($source, $folder, $as);

    expect($run['exit'])->toBe(1, 'exit untuk as='.json_encode($as).' source='.json_encode($source).' folder='.$folder)
        ->and(trim($run['output']))->not->toBe('')
        ->and($run['output'])->not->toContain('pondok.theme_export')
        ->and(themeInstalledBaseTree())->toBe($installed, 'tema terpasang tidak berubah')
        ->and(is_dir($watch) ? exvTree($watch) : null)->toBe($before, 'tujuan tidak berubah');
}

it('F111 AC-11 VERIFY: rejects tricky --as slugs (case, lokal variants, null byte, newline, unicode, official, boundary length)', function () {
    $local = exvLocal();
    $target = exvTarget();

    foreach (['Lokal-x', 'lokal-', 'lokal-x', 'lokal-salinan', "a\0b", "zz-terbit\n", "zz-terbit\0", 'zz-térbit', 'zz_terbit', '..', 'default', str_repeat('a', 41), 'a'] as $as) {
        exvAssertRejected($local, $target['folder'], $as);
    }

    expect(file_exists($target['folder']))->toBeFalse();
});

it('F111 AC-11 VERIFY: accepts the slug length boundaries 2 and 40 and a bare "lokal"', function () {
    $local = exvLocal();
    $target = exvTarget();

    foreach (['ab', str_repeat('a', 40), 'lokal'] as $as) {
        expect(exvRun($local, $target['folder'], $as)['exit'])->toBe(0, "as={$as}")
            ->and(json_decode((string) file_get_contents("{$target['folder']}/{$as}/theme.json"), true)['slug'])->toBe($as);
    }
});

it('F111 AC-11 VERIFY: rejects tricky source slugs', function () {
    $local = exvLocal();
    $target = exvTarget();

    foreach (["{$local}\n", "{$local}\0", " {$local}", strtoupper($local), "{$local}/../{$local}", 'lokal-', 'lokal', "../{$local}", ''] as $source) {
        exvAssertRejected($source, $target['folder'], 'zz-terbit');
    }
});

it('F111 AC-11 VERIFY: --as equal to an installed non-lokal slug only writes into the folder', function () {
    $local = exvLocal();
    $target = exvTarget();
    $installed = themeInstalledBaseTree();

    expect(exvRun($local, $target['folder'], 'zz')['exit'])->toBe(0)
        ->and(themeInstalledBaseTree())->toBe($installed)
        ->and(file_exists("{$target['folder']}/zz/theme.json"))->toBeTrue();
});

it('F111 AC-11 VERIFY: an existing <folder>/<as> that is a file, a dangling symlink, or a symlinked dir is left untouched', function () {
    $local = exvLocal();

    foreach (['file', 'dangling', 'dirlink'] as $kind) {
        $target = exvTarget();
        mkdir($target['folder']);
        match ($kind) {
            'file' => file_put_contents("{$target['folder']}/zz-terbit", 'milik saya'),
            'dangling' => symlink("{$target['root']}/tidak-ada", "{$target['folder']}/zz-terbit"),
            'dirlink' => (function () use ($target) {
                mkdir("{$target['root']}/lain");
                file_put_contents("{$target['root']}/lain/x.txt", 'x');
                symlink("{$target['root']}/lain", "{$target['folder']}/zz-terbit");
            })(),
        };

        exvAssertRejected($local, $target['folder'], 'zz-terbit', $target['root']);
    }
});

it('F111 AC-11 VERIFY: a destination folder that is a regular file or dangling symlink fails cleanly without deleting it', function () {
    $local = exvLocal();

    foreach (['file', 'dangling'] as $kind) {
        $target = exvTarget();
        $kind === 'file' ? file_put_contents($target['folder'], 'penting') : symlink("{$target['root']}/tidak-ada", $target['folder']);

        exvAssertRejected($local, $target['folder'], 'zz-terbit', $target['root']);
        expect(is_link($target['folder']) || is_file($target['folder']))->toBeTrue("{$kind} masih ada");
    }
});

it('F111 AC-11 VERIFY: an unwritable destination folder fails cleanly and keeps its contents', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root mengabaikan izin tulis');
    }

    $local = exvLocal();
    $target = exvTarget();
    mkdir($target['folder']);
    file_put_contents("{$target['folder']}/ada.txt", 'ada');
    chmod($target['folder'], 0555);

    try {
        exvAssertRejected($local, $target['folder'], 'zz-terbit', $target['root']);
    } finally {
        chmod($target['folder'], 0777);
    }
});

it('F111 AC-11 VERIFY: refuses a destination folder inside the installed themes root', function () {
    $local = exvLocal();

    exvAssertRejected($local, themeInstalledSandbox(), 'zz-baru', themeInstalledSandbox());
});

it('F111 AC-11 VERIFY: refuses a destination folder inside the source theme', function () {
    $local = exvLocal();
    $source = themeInstalledSandbox()."/{$local}";

    exvAssertRejected($local, $source, 'zz-baru', $source);
});

it('F111 AC-11 VERIFY: rescopes only the exact source scope token and keeps sha256/size consistent', function () {
    $local = exvLocal(['extra' => "\n/* body[data-pt-theme=\"lokal-abc\"] superset; data-pt-theme=\"lokal-ab\" telanjang; body[data-pt-theme=\"zz\"] asal */\n{S} [data-pt=\"tambah\"]{color:var(--pt-accent)}\n"]);
    $target = exvTarget();

    expect(exvRun($local, $target['folder'], 'zz-terbit')['exit'])->toBe(0);

    $dir = "{$target['folder']}/zz-terbit";
    $css = (string) file_get_contents("{$dir}/theme.css");
    $manifest = app(ValidatePortalTheme::class)->handle("{$dir}/theme.json");
    app(ValidateThemePackage::class)->handle($dir, $manifest);

    expect($css)->toContain('body[data-pt-theme="lokal-abc"]')
        ->and($css)->toContain('data-pt-theme="lokal-ab" telanjang')
        ->and($css)->not->toContain('body[data-pt-theme="lokal-ab"]')
        ->and($css)->toContain('body[data-pt-theme="zz-terbit"] [data-pt="tambah"]')
        ->and($manifest['assets']['css'])->toMatchArray(['sha256' => hash('sha256', $css), 'size' => strlen($css)]);
});

it('F111 AC-11 VERIFY: exports many fonts byte-identical and leaves previous.json and stray asset folders behind', function () {
    $fonts = $faces = [];

    foreach (['a', 'b', 'c'] as $tag) {
        $fonts[] = ['path' => "fonts/zz-{$tag}.woff2", 'family' => "Zz Uji {$tag}", 'weight' => 400, 'style' => 'normal', 'license' => 'OFL-1.1', 'bytes' => themePkgFontBytes($tag)];
        $faces[] = themePkgFontFace(['family' => "Zz Uji {$tag}", 'path' => "fonts/zz-{$tag}.woff2"]);
    }

    $local = exvLocal(['fonts' => $fonts, 'fontFace' => implode("\n", $faces)]);
    $localDir = themeInstalledSandbox()."/{$local}";
    file_put_contents("{$localDir}/previous.json", '{"bukan":"tema"}');
    mkdir("{$localDir}/assets/yatim000000", 0777, true);
    file_put_contents("{$localDir}/assets/yatim000000/sisa.txt", 'sisa');
    $target = exvTarget();

    expect(exvRun($local, $target['folder'], 'zz-terbit')['exit'])->toBe(0);

    $dir = "{$target['folder']}/zz-terbit";
    expect(array_keys(exvTree($target['folder'])))->toBe(['zz-terbit', 'zz-terbit/fonts', 'zz-terbit/fonts/zz-a.woff2', 'zz-terbit/fonts/zz-b.woff2', 'zz-terbit/fonts/zz-c.woff2', 'zz-terbit/theme.css', 'zz-terbit/theme.json'])
        ->and((string) file_get_contents("{$dir}/fonts/zz-b.woff2"))->toBe(themePkgFontBytes('b'));
});

/** Sumber yang CSS-nya lolos 128 KiB hanya dengan slug pendek: ekspor ke slug 40 karakter melewati batas. */
function exvOversizedLocal(): string
{
    $s = themePkgScope('zz');
    $css = themePkgCss('zz', str_repeat("{$s} [data-pt=\"x\"]{color:var(--pt-accent)}\n", 1500));
    $slug = exvLocal(['css' => $css]);
    $size = strlen((string) file_get_contents(themeInstalledSandbox()."/{$slug}/assets/".substr(hash_file('sha256', themeInstalledSandbox()."/{$slug}/theme.json"), 0, 12).'/theme.css'));

    expect($size)->toBeLessThan(131072)->and($size + 32 * 1500)->toBeGreaterThan(131072);

    return $slug;
}

it('F111 AC-11 VERIFY: a failure after staging was written leaves no created folder, not even new parents', function () {
    $local = exvOversizedLocal();
    $target = exvTarget();
    $folder = "{$target['root']}/a/b/keluar";
    $installed = themeInstalledBaseTree();

    $run = exvRun($local, $folder, str_repeat('a', 40));

    expect($run['exit'])->toBe(1)
        ->and(trim($run['output']))->not->toBe('')
        ->and(glob("{$target['root']}/*"))->toBe([], 'tidak ada folder induk baru tersisa')
        ->and(themeInstalledBaseTree())->toBe($installed);
});

it('F111 AC-11 VERIFY: a failure after staging was written keeps an existing folder as it was (no _staging leftovers)', function () {
    $local = exvOversizedLocal();
    $target = exvTarget();
    mkdir($target['folder']);
    file_put_contents("{$target['folder']}/ada.txt", 'ada');

    exvAssertRejected($local, $target['folder'], str_repeat('a', 40), $target['root']);
});

it('F111 AC-11 VERIFY: a relative destination folder resolves against the working directory', function () {
    $local = exvLocal();
    $target = exvTarget();
    $cwd = getcwd();
    chdir($target['root']);

    try {
        $run = exvRun($local, 'keluar', 'zz-terbit');
    } finally {
        chdir($cwd);
    }

    expect($run['exit'])->toBe(0)
        ->and(file_exists("{$target['root']}/keluar/zz-terbit/theme.json"))->toBeTrue();
});
