<?php

use App\Actions\Konten\ListPortalThemes;
use App\Actions\Konten\RollbackPortalTheme;
use App\Actions\Konten\SaveEditedTheme;
use Illuminate\Auth\Access\AuthorizationException;

/*
| F111 PR2, AC-4 sampai AC-8 (rencana F110). Kontrak yang diasumsikan:
| `SaveEditedTheme::handle(string $slug, array $input, User $actor): string` mengembalikan versi baru (patch naik).
| Kunci `$input` yang dikenal (semuanya opsional): `tokens` (peta `--nama` => `#rrggbb`), `layout` (daftar node puncak `home.layout`
| utuh), `name`, `description`, `palette`, `surface`, `font`. Kunci lain ditolak. Penolakan = `InvalidArgumentException` berpesan
| Indonesia, folder terpasang tidak berubah. Slug bukan `lokal-*`, tema resmi, dan tema aktif ditolak. Log `konten` event `edited`.
*/

beforeEach(function () {
    kontenBoot();
    themeSandbox();
    themeInstalledSandbox();
    $this->admin = kontenUser('super-admin');
});

/**
 * Paket `lokal-ubah` terpasang (v1.0.0): token hex `--pt-fg`, `--pt-bg`, `--pt-accent`, token non-hex `--pt-gap`, pasangan kontras fg/bg.
 * `home.layout` memuat `container` ber-`children`.
 *
 * @return array<string, mixed>
 */
function seLocal(string $slug = 'lokal-ubah'): array
{
    $css = str_replace('--pt-accent:#b4531a;', '--pt-accent:#b4531a;--pt-gap:1rem;', themePkgCss($slug));
    $layout = themeBaseManifest()['home']['layout'];
    array_unshift($layout, ['component' => 'container', 'style' => ['tone' => 'muted'], 'children' => [['component' => 'cta', 'variant' => 'band']]]);

    $pkg = themePkg(['slug' => $slug, 'css' => $css, 'manifest' => ['home' => ['layout' => $layout]]]);
    themePkgPlace($pkg);

    return $pkg;
}

function seSave(array $input, string $slug = 'lokal-ubah'): string
{
    return app(SaveEditedTheme::class)->handle($slug, $input, test()->admin);
}

function seRejected(array $input, ?string $reason = null, string $slug = 'lokal-ubah'): void
{
    $before = themeInstalledTree();

    themeAssertRejected(fn () => seSave($input, $slug), $reason);

    expect(themeInstalledTree())->toBe($before, 'folder terpasang tidak berubah')
        ->and(glob(themeInstalledSandbox().'/_staging/*') ?: [])->toBe([], 'tidak ada _staging tersisa');
}

/**
 * @return array<string, mixed>
 */
function seInstalled(string $slug = 'lokal-ubah'): array
{
    return json_decode((string) themeInstalledFile($slug), true);
}

function seAssetsDir(string $slug = 'lokal-ubah'): string
{
    return themeInstalledSandbox()."/{$slug}/assets/".substr(hash('sha256', (string) themeInstalledFile($slug)), 0, 12);
}

it('F111 AC-4: changes only the requested tokens and keeps the rest of the css byte-identical', function () {
    $pkg = seLocal();

    $version = seSave(['tokens' => ['--pt-accent' => '#112233', '--pt-bg' => '#ffffff']]);

    $expected = str_replace(['--pt-accent:#b4531a;', '--pt-bg:#fbf7ee;'], ['--pt-accent:#112233;', '--pt-bg:#ffffff;'], $pkg['files']['theme.css']);
    $manifest = seInstalled();

    expect($version)->toBe('1.0.1')
        ->and($manifest['version'])->toBe('1.0.1')
        ->and((string) file_get_contents(seAssetsDir().'/theme.css'))->toBe($expected)
        ->and($manifest['assets']['css'])->toMatchArray(['sha256' => hash('sha256', $expected), 'size' => strlen($expected)])
        ->and((string) file_get_contents(seAssetsDir().'/'.F105_FONT_PATH))->toBe($pkg['files'][F105_FONT_PATH])
        ->and($manifest['assets']['fonts'])->toBe($pkg['manifest']['assets']['fonts'])
        ->and($manifest['home'])->toBe($pkg['manifest']['home'])
        ->and(collect(app(ListPortalThemes::class)->handle())->firstWhere('slug', 'lokal-ubah'))->toMatchArray(['source' => 'local', 'valid' => true, 'version' => '1.0.1']);
});

it('F111 AC-4: refuses unknown tokens, non-hex tokens, values that are not #rrggbb, and unknown input keys', function (array $input) {
    seLocal();

    seRejected($input);
})->with([
    'unknown token' => [['tokens' => ['--pt-tidak-ada' => '#112233']]],
    'token that is not hex in the css' => [['tokens' => ['--pt-gap' => '#112233']]],
    'token without dashes' => [['tokens' => ['pt-accent' => '#112233']]],
    'short hex' => [['tokens' => ['--pt-accent' => '#fff']]],
    'long hex' => [['tokens' => ['--pt-accent' => '#1122334']]],
    'not hex digits' => [['tokens' => ['--pt-accent' => '#gggggg']]],
    'named color' => [['tokens' => ['--pt-accent' => 'red']]],
    'function' => [['tokens' => ['--pt-accent' => 'rgb(0,0,0)']]],
    'injection' => [['tokens' => ['--pt-accent' => '#112233;} body{display:none']]],
    'url' => [['tokens' => ['--pt-accent' => 'url(https://x.test/a.png)']]],
    'array value' => [['tokens' => ['--pt-accent' => ['#112233']]]],
    'tokens not a map' => [['tokens' => '#112233']],
    'unknown input key' => [['tokens' => ['--pt-accent' => '#112233'], 'css' => 'body{}']],
    'font upload key' => [['font_file' => 'x.woff2']],
]);

it('F111 AC-5: refuses a token change that pushes an assets.contrast pair below the threshold, changing nothing', function () {
    seLocal();

    seRejected(['tokens' => ['--pt-fg' => '#f5f1e6']], 'pondok.theme_assets.contrast_low');
    seRejected(['tokens' => ['--pt-bg' => '#1a2b1d']], 'pondok.theme_assets.contrast_low');

    expect(seSave(['tokens' => ['--pt-fg' => '#000000']]))->toBe('1.0.1');
});

it('F111 AC-6: saves order, add/remove, variants and scalar styles of top-level nodes, keeping children and header/footer', function () {
    $pkg = seLocal();
    $layout = [
        ['component' => 'cta', 'variant' => 'card', 'style' => ['tone' => 'brand', 'radius' => 'lg', 'shadow' => 'sm', 'border' => 'top', 'gap' => 'sm', 'align' => 'center', 'width' => 'narrow', 'padding' => ['base' => 'lg']]],
        ['component' => 'container', 'style' => ['tone' => 'muted'], 'children' => [['component' => 'cta', 'variant' => 'band']]],
        ['component' => 'hero', 'variant' => 'split'],
        ['component' => 'feed_list', 'variant' => 'magazine'],
    ];

    seSave(['layout' => $layout]);

    $manifest = seInstalled();

    expect($manifest['home'])->toBe(['layout' => $layout])
        ->and($manifest['header'])->toBe($pkg['manifest']['header'])
        ->and($manifest['footer'])->toBe($pkg['manifest']['footer'])
        ->and($manifest['assets']['css']['sha256'])->toBe($pkg['manifest']['assets']['css']['sha256'])
        ->and((string) file_get_contents(seAssetsDir().'/theme.css'))->toBe($pkg['files']['theme.css'])
        ->and((string) file_get_contents(seAssetsDir().'/'.F105_FONT_PATH))->toBe($pkg['files'][F105_FONT_PATH]);
});

it('F111 AC-6: refuses unknown components, variants, style values, responsive styles and a malformed layout', function (array $layout) {
    seLocal();

    seRejected(['layout' => $layout]);
})->with([
    'unknown component' => [[['component' => 'iframe']]],
    'variant not allowed for component' => [[['component' => 'hero', 'variant' => 'magazine']]],
    'variant on component without variants' => [[['component' => 'text_image', 'variant' => 'cards']]],
    'unknown style value' => [[['component' => 'hero', 'style' => ['tone' => 'neon']]]],
    'unknown style property' => [[['component' => 'hero', 'style' => ['background' => 'red']]]],
    'responsive padding' => [[['component' => 'hero', 'style' => ['padding' => ['base' => 'sm', 'lg' => 'lg']]]]],
    'responsive columns' => [[['component' => 'columns', 'style' => ['columns' => ['base' => 2]]]]],
    'array for a scalar style' => [[['component' => 'hero', 'style' => ['tone' => ['base' => 'dark']]]]],
    'empty layout' => [[]],
    'node is not an object' => [['hero']],
]);

it('F111 AC-7: bumps the patch version, keeps the old manifest as previous.json, and rollback restores it', function () {
    $pkg = seLocal();

    seSave(['tokens' => ['--pt-accent' => '#112233']]);
    $second = seSave(['tokens' => ['--pt-accent' => '#445566']]);

    expect($second)->toBe('1.0.2')
        ->and(json_decode((string) themeInstalledFile('lokal-ubah', 'previous.json'), true)['version'])->toBe('1.0.1');

    app(RollbackPortalTheme::class)->handle('lokal-ubah', $this->admin);

    expect(seInstalled()['version'])->toBe('1.0.1')
        ->and((string) file_get_contents(seAssetsDir().'/theme.css'))->toContain('--pt-accent:#112233;')
        ->and(collect(app(ListPortalThemes::class)->handle())->firstWhere('slug', 'lokal-ubah'))->toMatchArray(['valid' => true, 'version' => '1.0.1'])
        ->and($pkg['version'])->toBe('1.0.0');
});

it('F111 AC-7: saves name, description and look enums, and logs an edited event with the actor', function () {
    seLocal();

    seSave(['name' => 'Nama Baru', 'description' => 'Deskripsi baru.', 'palette' => 'hijau', 'surface' => 'krem']);

    $log = kontenActivities()->where('event', 'edited');

    expect(seInstalled())->toMatchArray(['name' => 'Nama Baru', 'description' => 'Deskripsi baru.', 'palette' => 'hijau', 'surface' => 'krem', 'version' => '1.0.1'])
        ->and($log)->toHaveCount(1)
        ->and($log->first()->properties['slug'])->toBe('lokal-ubah')
        ->and($log->first()->properties['version'])->toBe('1.0.1')
        ->and($log->first()->causer_id)->toBe($this->admin->id);

    seRejected(['palette' => 'ungu']);
    seRejected(['surface' => 'merah']);
    seRejected(['name' => '']);
    seRejected(['name' => str_repeat('a', 61)]);
    expect(kontenActivities()->where('event', 'edited'))->toHaveCount(1);
});

it('F111 AC-7: a failed save changes neither theme.json, previous.json nor assets, and logs nothing', function () {
    seLocal();
    seSave(['tokens' => ['--pt-accent' => '#112233']]);
    $before = themeInstalledTree();

    seRejected(['tokens' => ['--pt-accent' => '#445566', '--pt-fg' => '#fbf7ef']], 'pondok.theme_assets.contrast_low');

    expect(themeInstalledTree())->toBe($before)
        ->and(kontenActivities()->where('event', 'edited'))->toHaveCount(1);
});

it('F111 AC-8: refuses non-lokal, official, active and malformed slugs', function () {
    themePkgPlace(themePkg(['slug' => 'zz-terpasang']));
    seLocal('lokal-aktif');
    themeActivate('lokal-aktif');

    seRejected(['tokens' => ['--pt-accent' => '#112233']], null, 'zz-terpasang');
    seRejected(['tokens' => ['--pt-accent' => '#112233']], null, 'default');
    seRejected(['tokens' => ['--pt-accent' => '#112233']], null, 'lokal-aktif');
    seRejected(['tokens' => ['--pt-accent' => '#112233']], null, '../lokal-aktif');
    seRejected(['tokens' => ['--pt-accent' => '#112233']], null, 'lokal-tidak-ada');

    themeActivate('default');

    expect(seSave(['tokens' => ['--pt-accent' => '#112233']], 'lokal-aktif'))->toBe('1.0.1');
});

it('F111 AC-8: refuses an actor without manage:themes', function () {
    seLocal();
    $before = themeInstalledTree();

    expect(fn () => app(SaveEditedTheme::class)->handle('lokal-ubah', ['name' => 'Ditolak'], kontenUser('bendahara')))->toThrow(AuthorizationException::class)
        ->and(themeInstalledTree())->toBe($before);
});
