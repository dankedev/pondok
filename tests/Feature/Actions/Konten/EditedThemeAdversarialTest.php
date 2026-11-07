<?php

use App\Actions\Konten\DuplicatePortalTheme;
use App\Actions\Konten\ListPortalThemes;
use App\Actions\Konten\SaveEditedTheme;
use Illuminate\Support\Facades\File;

/*
| F111 PR2, VERIFY adversarial: celah berkas dan injeksi pada DuplicatePortalTheme dan SaveEditedTheme.
*/

beforeEach(function () {
    kontenBoot();
    themeSandbox();
    themeInstalledSandbox();
    $this->admin = kontenUser('super-admin');
});

function advDuplicate(string $source, mixed $name): string
{
    return app(DuplicatePortalTheme::class)->handle($source, ['name' => $name], test()->admin);
}

function advSave(string $slug, array $input): string
{
    return app(SaveEditedTheme::class)->handle($slug, $input, test()->admin);
}

/** Tempatkan paket `lokal-adv` dengan CSS khusus (scope sudah dipasang lewat themePkgCss). */
function advPlace(string $slug, string $extra = '', array $options = []): array
{
    $pkg = themePkg(['slug' => $slug, 'css' => themePkgCss($slug, $extra), ...$options]);
    themePkgPlace($pkg);

    return $pkg;
}

function advCssOf(string $slug): string
{
    $hash = substr(hash('sha256', (string) themeInstalledFile($slug)), 0, 12);

    return (string) file_get_contents(themeInstalledSandbox()."/{$slug}/assets/{$hash}/theme.css");
}

it('VERIFY: slug sumber berbahaya tidak pernah menyentuh filesystem di luar folder tema', function () {
    advPlace('zz-sumber');
    $base = themeInstalledBaseTree();

    foreach (['lokal-../zz-sumber', 'lokal-a/b', 'ZZ-SUMBER', "zz-sumber\0", str_repeat('a', 41), '..', '.', 'zz-sumber/../zz-sumber', '_staging/x'] as $source) {
        try {
            advDuplicate($source, 'Aman');
            test()->fail("Sumber '{$source}' seharusnya ditolak");
        } catch (InvalidArgumentException) {
        }
    }

    expect(themeInstalledBaseTree())->toBe($base);
});

it('VERIFY: nama berisi traversal, unicode, null byte, HTML dan tanda kutip menghasilkan slug sah di bawah folder tema atau ditolak', function () {
    advPlace('zz-sumber');

    foreach (['../../etc/passwd', "Tema\0Baru", 'Tema Ünïcödé', '日本語', '<img src=x onerror=alert(1)>', 'Tema "kutip" \'satu\'', '_staging'] as $name) {
        try {
            $slug = advDuplicate('zz-sumber', $name);
        } catch (InvalidArgumentException) {
            continue;
        }

        expect($slug)->toMatch('/^lokal-[a-z0-9-]{1,34}\z/', "slug untuk {$name}")
            ->and(is_file(themeInstalledSandbox()."/{$slug}/theme.json"))->toBeTrue()
            ->and(file_exists(themeInstalledSandbox().'/../'.basename($slug)))->toBeFalse();
    }

    expect(glob(themeInstalledSandbox().'/*/') ?: [])->not->toBeEmpty()
        ->and(array_map('basename', glob(themeInstalledSandbox().'/*', GLOB_ONLYDIR) ?: []))->each->toMatch('/^(_staging|[a-z0-9-]+)\z/');
});

it('VERIFY: duplikat tidak menyalin previous.json dan tidak menyalin scope tema lain yang slugnya superset', function () {
    // komentar menyebut scope slug lain yang diawali slug sumber; hanya scope sumber persis yang boleh diganti
    $pkg = advPlace('zz-sumber', "\n/* lihat body[data-pt-theme=\"zz-sumber-2\"] dan body[data-pt-theme=\"zz-sumber\"] */\n");
    file_put_contents(themeInstalledSandbox().'/zz-sumber/previous.json', (string) themeInstalledFile('zz-sumber'));

    $slug = advDuplicate('zz-sumber', 'Salinan');
    $css = advCssOf($slug);

    expect($css)->toContain('body[data-pt-theme="zz-sumber-2"]')
        ->and($css)->not->toContain('data-pt-theme="zz-sumber"')
        ->and(substr_count($css, 'data-pt-theme="lokal-salinan"'))->toBe(substr_count($pkg['files']['theme.css'], 'data-pt-theme="zz-sumber"'))
        ->and(is_file(themeInstalledSandbox()."/{$slug}/previous.json"))->toBeFalse()
        ->and(glob(themeInstalledSandbox()."/{$slug}/assets/*"))->toHaveCount(1);
});

it('VERIFY: menduplikat tema aktif dan tema resmi tidak mengubah sumber (byte-identik)', function () {
    advPlace('lokal-aktif');
    themeActivate('lokal-aktif');
    $official = File::allFiles(themeSandbox());
    $officialHash = collect($official)->mapWithKeys(fn ($f) => [$f->getPathname() => md5_file($f->getPathname())])->all();
    $installed = themeInstalledTree();

    advDuplicate('lokal-aktif', 'Kembaran Aktif');
    advDuplicate('default', 'Kembaran Resmi');

    expect(array_intersect_key(themeInstalledTree(), $installed))->toBe($installed)
        ->and(array_intersect_key(collect(File::allFiles(themeSandbox()))->mapWithKeys(fn ($f) => [$f->getPathname() => md5_file($f->getPathname())])->all(), $officialHash))->toBe($officialHash);
});

it('VERIFY: folder tujuan yang sudah ada tanpa theme.json, atau symlink patah, tidak ditimpa dan tidak menulis ke luar', function () {
    advPlace('zz-sumber');
    $root = themeInstalledSandbox();
    mkdir("{$root}/lokal-sisa", 0777, true);
    file_put_contents("{$root}/lokal-sisa/catatan.txt", 'jangan hilang');
    $outside = dirname($root).'/luar-target';
    symlink($outside, "{$root}/lokal-tautan");
    foreach (['Sisa', 'Tautan'] as $name) {
        try {
            advDuplicate('zz-sumber', $name);
            test()->fail("Tujuan {$name} seharusnya ditolak");
        } catch (InvalidArgumentException) {
        }
    }

    expect(file_get_contents("{$root}/lokal-sisa/catatan.txt"))->toBe('jangan hilang')
        ->and(file_exists($outside))->toBeFalse()
        ->and(is_file("{$root}/lokal-sisa/theme.json"))->toBeFalse();

    @unlink("{$root}/lokal-tautan");
})->skip(fn () => ! function_exists('symlink'));

it('VERIFY: tema resmi berslug lokal-* tidak bisa disunting dan tidak bisa ditimpa duplikat', function () {
    $slug = themeInstall(themeOfficialManifest('default'), 'lokal-resmi');
    $official = themeSandbox()."/{$slug}/theme.json";
    $before = md5_file($official);

    expect(fn () => advSave($slug, ['name' => 'Diubah']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => advDuplicate('default', 'Resmi'))->toThrow(InvalidArgumentException::class)
        ->and(md5_file($official))->toBe($before)
        ->and(collect(app(ListPortalThemes::class)->handle())->firstWhere('slug', $slug)['source'])->toBe('official');
});

it('VERIFY: ListPortalThemes hanya menandai folder lokal-* terpasang sebagai local', function () {
    themePkgPlace(themePkg(['slug' => 'zz-biasa']));
    themePkgPlace(themePkg(['slug' => 'lokal-saya']));
    themePkgPlace(themePkg(['slug' => 'xlokal-saya']));
    $sources = collect(app(ListPortalThemes::class)->handle())->pluck('source', 'slug');

    expect($sources['zz-biasa'])->toBe('installed')
        ->and($sources['lokal-saya'])->toBe('local')
        ->and($sources['xlokal-saya'])->toBe('installed')
        ->and($sources['default'])->toBe('official');
});

it('VERIFY: token dengan nilai heks huruf besar tersimpan dan kontras tetap dihitung', function () {
    advPlace('lokal-adv');

    advSave('lokal-adv', ['tokens' => ['--pt-accent' => '#AbCdEf', '--pt-fg' => '#000000']]);

    expect(advCssOf('lokal-adv'))->toContain('--pt-accent:#AbCdEf;')->toContain('--pt-fg:#000000;')
        ->and(fn () => advSave('lokal-adv', ['tokens' => ['--pt-fg' => '#FBF7EF']]))->toThrow(InvalidArgumentException::class);
});

it('VERIFY: token yang dideklarasikan dua kali, atau juga di aturan lain, ditolak dan folder tidak berubah', function (string $extra) {
    advPlace('lokal-adv', $extra);
    $before = themeInstalledTree();

    expect(fn () => advSave('lokal-adv', ['tokens' => ['--pt-accent' => '#112233']]))->toThrow(InvalidArgumentException::class)
        ->and(themeInstalledTree())->toBe($before)
        ->and(glob(themeInstalledSandbox().'/_staging/*') ?: [])->toBe([]);
})->with([
    'deklarasi ganda di blok scope' => ["\n{S}{--pt-accent:#010203}\n"],
    'di dalam media' => ["\n@media (min-width:600px){{S}{--pt-accent:#010203}}\n"],
]);

it('VERIFY: komentar CSS yang meniru deklarasi token tidak membuat simpan diam-diam tanpa efek', function () {
    $pkg = advPlace('lokal-adv');
    $css = $pkg['files']['theme.css'];
    expect($css)->toContain('--pt-accent:#b4531a;');

    // sisipkan komentar berisi deklarasi sama persis SEBELUM deklarasi asli di blok scope
    $decoy = str_replace('{--pt-fg:', '{/* --pt-accent:#b4531a; */--pt-fg:', $css);
    $pkg = themePkg(['slug' => 'lokal-dekoy', 'css' => str_replace('lokal-adv', 'lokal-dekoy', $decoy)]);
    themePkgPlace($pkg);

    try {
        advSave('lokal-dekoy', ['tokens' => ['--pt-accent' => '#112233']]);
    } catch (InvalidArgumentException) {
        expect(true)->toBeTrue();

        return;
    }

    expect(advCssOf('lokal-dekoy'))->toContain('--pt-accent:#112233;')
        ->and(preg_match('/\*\/--pt-fg[^}]*--pt-accent:#b4531a/', advCssOf('lokal-dekoy')))->toBe(0);
});

it('VERIFY: token ber-awalan sama tidak ikut berubah dan prefiks parsial ditolak', function () {
    advPlace('lokal-adv');
    $css = themePkgCss('lokal-adv', "\n{S}{--pt-bg-soft:#eeeeee}\n");
    $pkg = themePkg(['slug' => 'lokal-pre', 'css' => str_replace('lokal-adv', 'lokal-pre', $css)]);
    themePkgPlace($pkg);

    advSave('lokal-pre', ['tokens' => ['--pt-bg' => '#ffffff']]);

    expect(advCssOf('lokal-pre'))->toContain('--pt-bg-soft:#eeeeee')->toContain('--pt-bg:#ffffff;')
        ->and(fn () => advSave('lokal-pre', ['tokens' => ['--pt-b' => '#ffffff']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => advSave('lokal-pre', ['tokens' => ['--pt-bg ' => '#ffffff']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => advSave('lokal-pre', ['tokens' => ["--pt-bg\n" => '#ffffff']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => advSave('lokal-pre', ['tokens' => ['--pt-bg' => "#ffffff\n"]]))->toThrow(InvalidArgumentException::class);
});

it('VERIFY: layout yang bukan daftar murni, node asing, atau terlalu besar ditolak tanpa perubahan', function () {
    advPlace('lokal-adv');
    $before = themeInstalledTree();
    $hero = ['component' => 'hero'];

    foreach ([
        'map berkunci string' => ['a' => $hero],
        'string' => 'hero',
        'kunci asing di node' => [[...$hero, 'onclick' => 'x()']],
        'children di komponen tanpa anak' => [[...$hero, 'children' => [$hero]]],
        'style berupa string' => [[...$hero, 'style' => 'color:red']],
        '500 node' => array_fill(0, 500, $hero),
        'kedalaman berlebih' => [themeNested(40)],
    ] as $label => $layout) {
        try {
            advSave('lokal-adv', ['layout' => $layout]);
            test()->fail("Layout '{$label}' seharusnya ditolak");
        } catch (InvalidArgumentException) {
        }
    }

    expect(themeInstalledTree())->toBe($before)->and(glob(themeInstalledSandbox().'/_staging/*') ?: [])->toBe([]);
});

it('VERIFY: simpan beruntun melewati patch 9 ke 10 tetap sah, previous.json benar, aset tidak menumpuk', function () {
    advPlace('lokal-adv');

    for ($i = 1; $i <= 11; $i++) {
        $version = advSave('lokal-adv', ['name' => "Nama {$i}"]);
    }

    $previous = json_decode((string) themeInstalledFile('lokal-adv', 'previous.json'), true);

    expect($version)->toBe('1.0.11')
        ->and($previous['version'])->toBe('1.0.10')
        ->and(glob(themeInstalledSandbox().'/lokal-adv/assets/*'))->toHaveCount(2)
        ->and(collect(app(ListPortalThemes::class)->handle())->firstWhere('slug', 'lokal-adv'))->toMatchArray(['valid' => true, 'version' => '1.0.11', 'source' => 'local']);
});

it('VERIFY: folder tema berupa symlink ke luar tidak ditulis oleh simpan', function () {
    $pkg = advPlace('lokal-asli');
    $root = themeInstalledSandbox();
    $outside = dirname($root).'/luar-paket';
    File::copyDirectory("{$root}/lokal-asli", $outside);
    File::deleteDirectory("{$root}/lokal-asli");
    symlink($outside, "{$root}/lokal-asli");
    $outsideTree = collect(File::allFiles($outside))->mapWithKeys(fn ($f) => [$f->getRelativePathname() => md5_file($f->getPathname())])->all();

    try {
        advSave('lokal-asli', ['name' => 'Lewat tautan']);
        $threw = false;
    } catch (InvalidArgumentException) {
        $threw = true;
    }

    $after = collect(File::allFiles($outside))->mapWithKeys(fn ($f) => [$f->getRelativePathname() => md5_file($f->getPathname())])->all();
    @unlink("{$root}/lokal-asli");

    expect($threw)->toBeTrue('simpan lewat symlink harus ditolak')->and($after)->toBe($outsideTree)
        ->and($pkg['slug'])->toBe('lokal-asli');
});
