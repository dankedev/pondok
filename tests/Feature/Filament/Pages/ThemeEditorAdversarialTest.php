<?php

use App\Actions\Konten\BuildColorScale;
use App\Filament\Pages\Site\Theme;
use App\Filament\Pages\Site\ThemeEditor;
use App\Models\Setting;
use Livewire\Livewire;

/*
| F111 PR4, mode VERIFY: usaha mematahkan halaman `ThemeEditor` lewat state Livewire yang dimanipulasi, akses setelah izin berubah,
| integritas simpan, dan tampilan data. Test merah = temuan nyata (lihat laporan).
*/

beforeEach(function () {
    kontenBoot();
    themeSandbox();
    themeInstalledSandbox();
});

function taLocal(string $slug = 'lokal-ubah', array $manifest = []): array
{
    $pkg = themePkg(['slug' => $slug, 'manifest' => $manifest]);
    themePkgPlace($pkg);

    return $pkg;
}

function taPage(?string $slug = 'lokal-ubah')
{
    test()->actingAs(kontenUser('super-admin'));

    return Livewire::test(ThemeEditor::class)->set('selectedSlug', $slug);
}

function taManifest(string $slug = 'lokal-ubah'): array
{
    return json_decode((string) themeInstalledFile($slug), true);
}

it('403 on save and duplicate when the Konten module is switched off after the page was mounted', function () {
    taLocal();
    $page = taPage();
    Setting::set('modules.konten.enabled', false);
    $before = themeInstalledTree();

    foreach ([fn () => $page->set('data.name', 'Ganti Nama')->call('save'), fn () => $page->callAction('duplicate', ['source' => 'default', 'name' => 'Lolos Diam'])] as $attempt) {
        // modul mati: ditolak middleware modul (404) atau canAccess (403)
        try {
            $attempt()->assertForbidden();
        } catch (Throwable) {
            $attempt()->assertNotFound();
        }
    }

    expect(themeInstalledTree())->toBe($before);
});

it('ignores a selectedSlug that is traversal, official or not lokal-*, and save then writes nothing', function () {
    taLocal();
    $before = themeInstalledTree();

    foreach (['../../etc', 'lokal-../default', 'lokal-', 'LOKAL-UBAH', "lokal-ubah\0x", 'default'] as $slug) {
        taPage($slug)->assertSet('selectedSlug', null)->set('data.name', 'Ganti')->call('save');
    }

    // slug sah dipilih lebih dulu, lalu diganti ke tema resmi sebelum save: tidak boleh menyimpan data tema lokal ke tema lain
    taPage('lokal-ubah')->set('selectedSlug', 'default')->call('save');

    expect(themeInstalledTree())->toBe($before);
});

it('rejects tampered tokens (unknown name, non-hex value, new token, non-string) and leaves the theme untouched', function () {
    taLocal();
    $before = themeInstalledTree();

    foreach ([
        ['--pt-fg' => '#1a2b1c', '--pt-tidak-ada' => '#112233'],
        ['--pt-accent' => 'red'],
        ['--pt-accent' => '#12345'],
        ['--pt-accent' => '#112233;} body{display:none'],
        ['--pt-accent' => ['#112233']],
        ['--pt-accent' => '#112233', 'color' => '#000000'],
    ] as $tokens) {
        taPage()->set('data.tokens', $tokens)->call('save')->assertNotified(__('pondok.theme_editor.notifications.failed'));
    }

    expect(themeInstalledTree())->toBe($before);
});

it('rejects a tampered layout (unknown component, bad variant, bad style value, foreign key, empty list)', function () {
    $pkg = taLocal();
    $before = themeInstalledTree();
    $first = $pkg['manifest']['home']['layout'][0];

    foreach ([
        [['component' => 'script']],
        [['component' => 'hero', 'variant' => 'tidak-ada']],
        [['component' => 'hero', 'style' => ['tone' => 'merah-menyala']]],
        [['component' => 'hero', 'style' => ['background' => 'url(x)']]],
        [[...$first, 'onclick' => 'alert(1)']],
        [],
        ['bukan-node'],
    ] as $layout) {
        taPage()->set('data.layout', $layout)->call('save')->assertNotified(__('pondok.theme_editor.notifications.failed'));
    }

    expect(themeInstalledTree())->toBe($before);
});

it('rejects an oversized layout (many nodes) without writing', function () {
    $pkg = taLocal();
    $before = themeInstalledTree();
    $layout = array_fill(0, 300, $pkg['manifest']['home']['layout'][0]);

    taPage()->set('data.layout', $layout)->call('save')->assertNotified(__('pondok.theme_editor.notifications.failed'));

    expect(themeInstalledTree())->toBe($before);
});

it('rejects empty, overlong and control-character names', function () {
    taLocal();
    $before = themeInstalledTree();

    foreach (['', '   ', str_repeat('a', 300), "Nama\x00Rusak", "Baris\nBaru\x1b[31m"] as $name) {
        taPage()->set('data.name', $name)->call('save')->assertNotified(__('pondok.theme_editor.notifications.failed'));
    }

    expect(themeInstalledTree())->toBe($before);
});

it('keeps children of a top-level node and leaves header, footer, css fonts and package files byte-identical when only the layout is reordered', function () {
    $layout = [
        ['component' => 'container', 'style' => ['tone' => 'muted'], 'children' => [['component' => 'hero'], ['component' => 'cta', 'variant' => 'band']]],
        ['component' => 'quote', 'variant' => 'plain'],
    ];
    taLocal('lokal-ubah', ['home' => ['layout' => $layout]]);
    $old = taManifest();

    $page = taPage();
    $page->set('data.layout', array_reverse($page->get('data.layout')))->call('save')->assertNotified(__('pondok.theme_editor.notifications.saved'));
    $new = taManifest();

    expect($new['home']['layout'][1]['children'])->toBe($old['home']['layout'][0]['children'])
        ->and($new['header'])->toBe($old['header'])
        ->and($new['footer'])->toBe($old['footer'])
        ->and($new['assets']['fonts'])->toBe($old['assets']['fonts'])
        ->and($new['assets']['css'])->toBe($old['assets']['css']);
});

it('does not silently drop the responsive padding of a node when only another node is reordered', function () {
    $pkg = taLocal();
    $old = $pkg['manifest']['home']['layout'];

    $page = taPage();
    $page->set('data.layout', array_reverse($page->get('data.layout')))->call('save');
    $hero = collect(taManifest()['home']['layout'])->firstWhere('component', 'hero');

    expect($hero['style']['padding'])->toBe($old[0]['style']['padding']);
});

it('does not bump the version or archive previous.json when saving without any change', function () {
    taLocal();
    $before = themeInstalledTree();

    taPage()->call('save');

    expect(themeInstalledTree())->toBe($before);
});

it('saving twice in a row bumps the patch twice and previous.json is the first save', function () {
    taLocal();

    $page = taPage()->set('data.tokens.--pt-accent', '#112233')->call('save');
    $first = themeInstalledFile('lokal-ubah');
    $page->set('data.tokens.--pt-accent', '#223344')->call('save');

    expect(taManifest()['version'])->toBe('1.0.2')
        ->and(themeInstalledFile('lokal-ubah', 'previous.json'))->toBe($first);
});

it('accepts upper-case hex tokens and contrastRows matches BuildColorScale::contrast for any case', function () {
    taLocal();

    $page = taPage()->set('data.tokens.--pt-fg', '#000000')->set('data.tokens.--pt-bg', '#FBF7EE');
    $row = $page->instance()->contrastRows()[0];

    expect($row['ratio'])->toEqualWithDelta(BuildColorScale::contrast('#000000', '#fbf7ee'), 0.001);

    $page->set('data.tokens.--pt-accent', '#AABBCC')->call('save')->assertNotified(__('pondok.theme_editor.notifications.saved'));
});

it('contrastRows ignores garbage tokens instead of throwing', function () {
    taLocal();

    $rows = taPage()->set('data.tokens.--pt-fg', 'bukan-warna')->set('data.tokens.--pt-bg', ['x'])->instance()->contrastRows();

    expect($rows)->toBe([]);
});

it('previewLinks works without any static page and does not error after the Konten module is off', function () {
    taLocal();
    $page = taPage();

    expect($page->instance()->previewLinks())->toHaveCount(3);

    Setting::set('modules.konten.enabled', false);

    expect($page->instance()->previewLinks())->toBeArray();
});

it('escapes HTML in theme name and description on the editor, Tampilan and the duplicate notification', function () {
    $payload = '<img src=x onerror=alert(1)>';
    taLocal();
    taPage()->set('data.name', $payload)->set('data.description', $payload)->call('save');

    $html = taPage()->html();
    $this->actingAs(kontenUser('super-admin'));

    expect(taManifest()['name'])->toBe($payload)
        ->and($html)->not->toContain('<img src=x onerror')
        ->and(Livewire::test(Theme::class)->html())->not->toContain('<img src=x onerror');

    taPage(null)->callAction('duplicate', ['source' => 'lokal-ubah', 'name' => $payload])
        ->assertNotified(__('pondok.theme_editor.notifications.duplicated', ['name' => $payload]));
});

it('duplicate with a name that slugifies to nothing is a notification, not an error', function () {
    $before = themeInstalledTree();

    taPage(null)->callAction('duplicate', ['source' => 'default', 'name' => '!!!'])->assertNotified(__('pondok.theme_editor.notifications.failed'));

    expect(themeInstalledTree())->toBe($before);
});
