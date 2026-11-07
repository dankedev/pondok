<?php

use App\Actions\Konten\DuplicatePortalTheme;
use App\Actions\Konten\ValidatePortalTheme;
use App\Actions\Konten\ValidateThemePackage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/*
| F111 PR5, AC-11 (rencana F110). Kontrak yang diasumsikan:
| `php artisan pondok:theme-export {source} {folder} --as=<slug>`:
|  - `source` = slug tema `lokal-*` terpasang (dibaca lewat `ResolveThemePath`, hanya baca); `folder` = folder induk tujuan (dibuat bila belum ada).
|  - Menulis `<folder>/<as>/` berformat paket penerbit yang sama dengan masukan `pondok:theme-sign`: `theme.json`, lalu (bila sumber ber-`assets`)
|    `theme.css` dan `fonts/<nama>.woff2` langsung di folder itu (bukan `assets/<hash12>`). `theme.json` memakai slug `--as`; CSS dengan
|    `body[data-pt-theme="<sumber>"]` diganti ke `body[data-pt-theme="<as>"]` (sha256/size dihitung ulang); font byte-identik; versi semver sah
|    (hasil duplikat baru = `1.0.0`). Setelah `pondok:theme-sign --write-hashes` paket lolos `ValidatePortalTheme` + `ValidateThemePackage`.
|  - Sukses: exit 0 dan keluaran memuat path `<folder>/<as>`. Gagal: exit 1, pesan Indonesia (bukan kunci lang mentah) di keluaran, tanpa menulis apa pun
|    (folder tujuan tidak dibuat bila belum ada, tidak ada `<as>` setengah jadi, tidak ada `_staging`), dan tema sumber tetap sama.
|  - Ditolak: `--as` berawalan `lokal-`, slug tak sah (huruf besar, spasi, traversal, > 40 karakter, kosong/tanpa opsi), sumber bukan `lokal-*`
|    terpasang (resmi, terpasang non-lokal, atau tak ada), dan `<folder>/<as>` yang sudah ada (isinya tidak diubah).
*/

beforeEach(function () {
    kontenBoot();
    themeSandbox();
    themeInstalledSandbox();
    $this->keys = themeTestKeys(['k1']);
    $this->admin = kontenUser('super-admin');
});

/**
 * Folder tujuan sementara baru (belum ada), dibersihkan lewat `themePkgCleanup`.
 *
 * @return array{root: string, folder: string}
 */
function exTarget(): array
{
    $root = sys_get_temp_dir().'/pondok-f111-ekspor-'.Str::random(10);
    mkdir($root, 0777, true);
    $GLOBALS['themePkgRoots'][] = $root;

    return ['root' => $root, 'folder' => "{$root}/keluar"];
}

/**
 * Peta path relatif => md5 (atau 'dir') seluruh isi folder.
 *
 * @return array<string, string>
 */
function exTree(string $root): array
{
    $tree = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $path => $info) {
        $tree[substr((string) $path, strlen($root) + 1)] = $info->isDir() ? 'dir' : (string) md5_file((string) $path);
    }

    ksort($tree);

    return $tree;
}

/** Pasang sumber ber-`assets` lalu duplikat menjadi `lokal-cahaya-saya`. */
function exLocalWithAssets(): array
{
    $pkg = themePkg(['slug' => 'zz-kirim']);
    themePkgPlace($pkg);
    $slug = app(DuplicatePortalTheme::class)->handle('zz-kirim', ['name' => 'Cahaya Saya'], test()->admin);

    return ['pkg' => $pkg, 'local' => $slug];
}

/**
 * @return array{exit: int, output: string}
 */
function exRun(string $source, string $folder, ?string $as): array
{
    $exit = Artisan::call('pondok:theme-export', ['source' => $source, 'folder' => $folder, ...($as === null ? [] : ['--as' => $as])]);

    return ['exit' => $exit, 'output' => Artisan::output()];
}

/** Gagal bersih: exit 1, pesan Indonesia, tidak ada yang tertulis di tujuan, sumber dan `_staging` tidak berubah. */
function exRejected(string $source, string $folder, ?string $as): void
{
    $installed = themeInstalledTree();
    $base = themeInstalledBaseTree();
    $parent = dirname($folder);
    $destination = is_dir($parent) ? exTree($parent) : null;

    $run = exRun($source, $folder, $as);

    expect($run['exit'])->toBe(1)
        ->and(trim($run['output']))->not->toBe('')
        ->and($run['output'])->not->toContain('pondok.theme_export')
        ->and(themeInstalledTree())->toBe($installed, 'tema sumber tidak berubah')
        ->and(themeInstalledBaseTree())->toBe($base)
        ->and(glob(themeInstalledSandbox().'/_staging/*') ?: [])->toBe([], 'tidak ada _staging tersisa')
        ->and(is_dir($parent) ? exTree($parent) : null)->toBe($destination, 'tujuan tidak berubah');
}

it('F111 AC-11: exports a lokal theme into a package folder with rescoped css, byte-identical font, and the new slug', function () {
    ['pkg' => $pkg, 'local' => $local] = exLocalWithAssets();
    $target = exTarget();
    $installedBefore = themeInstalledTree();

    $run = exRun($local, $target['folder'], 'zz-terbit');

    $dir = "{$target['folder']}/zz-terbit";
    $manifest = json_decode((string) file_get_contents("{$dir}/theme.json"), true);
    $css = (string) file_get_contents("{$dir}/theme.css");

    expect($run['exit'])->toBe(0)
        ->and($run['output'])->toContain($dir)
        ->and(exTree($target['folder']))->toHaveKeys(['zz-terbit', 'zz-terbit/theme.json', 'zz-terbit/theme.css', 'zz-terbit/'.F105_FONT_PATH])
        ->and($manifest)->toMatchArray(['slug' => 'zz-terbit', 'version' => '1.0.0'])
        ->and($css)->toBe(str_replace(themePkgScope('zz-kirim'), themePkgScope('zz-terbit'), $pkg['files']['theme.css']))
        ->and($css)->not->toContain('data-pt-theme="zz-kirim"')
        ->and($css)->not->toContain($local)
        ->and((string) file_get_contents("{$dir}/".F105_FONT_PATH))->toBe($pkg['files'][F105_FONT_PATH])
        ->and($manifest['assets']['contrast'])->toBe($pkg['manifest']['assets']['contrast'])
        ->and(themeInstalledTree())->toBe($installedBefore, 'tema sumber tidak berubah');
});

it('F111 AC-11: the exported folder passes ValidateThemePackage after pondok:theme-sign --write-hashes', function () {
    ['local' => $local] = exLocalWithAssets();
    $target = exTarget();
    $dir = "{$target['folder']}/zz-terbit";

    expect(exRun($local, $target['folder'], 'zz-terbit')['exit'])->toBe(0);

    file_put_contents($secret = "{$target['root']}/rahasia.key", base64_encode($this->keys['k1']['secret']));
    $sign = Artisan::call('pondok:theme-sign', [
        'path' => $dir, '--write-hashes' => true, '--secret-file' => $secret, '--key-id' => 'k1',
        '--url' => 'https://pondok.org/themes/zz-terbit/1.0.0/theme.json',
    ]);
    $entry = json_decode(trim(Artisan::output()), true);

    $manifest = app(ValidatePortalTheme::class)->handle("{$dir}/theme.json");
    app(ValidateThemePackage::class)->handle($dir, $manifest);

    expect($sign)->toBe(0)
        ->and($entry)->toMatchArray(['slug' => 'zz-terbit'])
        ->and($manifest['slug'])->toBe('zz-terbit');
});

it('F111 AC-11: exports a theme without assets as theme.json only and it still signs', function () {
    themeInstalledWrite('zz-polos', themeBaseManifest());
    $local = app(DuplicatePortalTheme::class)->handle('zz-polos', ['name' => 'Salinan Polos'], $this->admin);
    $target = exTarget();
    $dir = "{$target['folder']}/zz-polos-terbit";

    $run = exRun($local, $target['folder'], 'zz-polos-terbit');

    file_put_contents($secret = "{$target['root']}/rahasia.key", base64_encode($this->keys['k1']['secret']));
    $sign = Artisan::call('pondok:theme-sign', [
        'path' => $dir, '--write-hashes' => true, '--secret-file' => $secret, '--key-id' => 'k1',
        '--url' => 'https://pondok.org/themes/zz-polos-terbit/1.0.0/theme.json',
    ]);

    expect($run['exit'])->toBe(0)
        ->and(array_keys(exTree($target['folder'])))->toBe(['zz-polos-terbit', 'zz-polos-terbit/theme.json'])
        ->and(json_decode((string) file_get_contents("{$dir}/theme.json"), true))->toMatchArray(['slug' => 'zz-polos-terbit'])
        ->and($sign)->toBe(0);
});

it('F111 AC-11: rejects an --as slug that starts with lokal-', function () {
    ['local' => $local] = exLocalWithAssets();

    exRejected($local, exTarget()['folder'], 'lokal-salinan');
});

it('F111 AC-11: rejects an invalid --as slug (uppercase, space, traversal, too long, too short, missing)', function (?string $as) {
    ['local' => $local] = exLocalWithAssets();
    $target = exTarget();

    exRejected($local, $target['folder'], $as);
    expect(file_exists($target['folder']))->toBeFalse()
        ->and(glob($target['root'].'/*'))->toBe([])
        ->and(file_exists(dirname($target['root']).'/luar'))->toBeFalse();
})->with([
    'huruf besar' => 'Zz-Terbit',
    'spasi' => 'zz terbit',
    'traversal' => '../luar',
    'garis miring' => 'a/b',
    'terlalu panjang' => str_repeat('a', 41),
    'terlalu pendek' => 'a',
    'kosong' => '',
    'tanpa opsi' => null,
]);

it('F111 AC-11: rejects a source that is not an installed lokal theme (official, installed non-lokal, missing, bad slug)', function (string $source) {
    $pkg = themePkg(['slug' => 'zz-bukan-lokal']);
    themePkgPlace($pkg);
    $target = exTarget();

    exRejected($source, $target['folder'], 'zz-terbit');
    expect(file_exists($target['folder']))->toBeFalse();
})->with([
    'resmi' => 'default',
    'terpasang non-lokal' => 'zz-bukan-lokal',
    'lokal tidak terpasang' => 'lokal-tidak-ada',
    'slug tak sah' => '../zz-bukan-lokal',
]);

it('F111 AC-11: rejects an existing destination folder and leaves its contents untouched', function () {
    ['local' => $local] = exLocalWithAssets();
    $target = exTarget();
    mkdir("{$target['folder']}/zz-terbit", 0777, true);
    file_put_contents("{$target['folder']}/zz-terbit/catatan.txt", 'milik saya');
    $before = exTree($target['folder']);

    exRejected($local, $target['folder'], 'zz-terbit');

    expect(exTree($target['folder']))->toBe($before)
        ->and((string) file_get_contents("{$target['folder']}/zz-terbit/catatan.txt"))->toBe('milik saya');
});

it('F111 AC-11: writes only into the given folder and never touches installed themes', function () {
    ['local' => $local] = exLocalWithAssets();
    $target = exTarget();
    $installed = themeInstalledBaseTree();

    expect(exRun($local, $target['folder'], 'zz-terbit')['exit'])->toBe(0)
        ->and(themeInstalledBaseTree())->toBe($installed)
        ->and(glob(themeInstalledSandbox().'/_staging/*') ?: [])->toBe([])
        ->and(array_keys(exTree($target['root'])))->each->toStartWith('keluar');
});
