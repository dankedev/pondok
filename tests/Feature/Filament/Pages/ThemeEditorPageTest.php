<?php

use App\Actions\Konten\BuildColorScale;
use App\Actions\Konten\DuplicatePortalTheme;
use App\Filament\Pages\Site\Theme;
use App\Filament\Pages\Site\ThemeCatalog;
use App\Filament\Pages\Site\ThemeEditor;
use App\Models\Setting;
use App\Models\StaticPage;
use Filament\Forms\Components\FileUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
| F111 PR4, AC-1, AC-5, AC-13 (rencana F110; AC-14 dicek manual). Kontrak halaman `App\Filament\Pages\Site\ThemeEditor` yang diasumsikan
| (pola `ThemeCatalog`): slug `site/theme-editor`, grup Situs, `getModule()` = 'konten', `canAccess()` = modul aktif + `manage:themes`.
|
| Livewire:
| - `selectedSlug` (?string, public): tema `lokal-*` yang sedang disunting. Mengubahnya memuat tema ke form. Tema bukan `lokal-*`
|   (resmi/terpasang/tidak ada) tidak dimuat: `selectedSlug` kembali null.
| - Form `statePath('data')`: `data.name`, `data.description`, `data.palette`, `data.surface`, `data.font` (Select enum, hanya untuk tema
|   TANPA css), `data.tokens` (peta `--nama` => `#rrggbb`, ColorPicker per token, hanya untuk tema ber-css), `data.layout` (Repeater node
|   puncak `home.layout`; tiap item berbentuk node theme.json: `component`, `variant`, `style`; `children` dipertahankan).
| - Aksi header `duplicate` (form: `source` = slug dari `ListPortalThemes` yang valid, `name`): memanggil `DuplicatePortalTheme`, lalu
|   `selectedSlug` = slug baru. Galat Action = notifikasi danger `pondok.theme_editor.notifications.failed` + pesan Action sebagai body.
| - `save()`: memanggil `SaveEditedTheme` dengan nilai form; sukses = notifikasi `pondok.theme_editor.notifications.saved`, gagal =
|   notifikasi danger `...failed` dengan pesan Action; tema tidak berubah saat gagal.
| - `contrastRows(): list<array{fg: string, bg: string, size: string, ratio: float, passes: bool}>` dihitung dari `data.tokens` saat ini
|   (tanpa simpan, tanpa menyentuh disk) untuk tiap pasangan `assets.contrast` (`BuildColorScale::contrast`); ditampilkan di halaman
|   lewat `pondok.theme_editor.contrast_ratio` (`:ratio` = angka satu desimal koma, mis. "12,3").
| - `previewLinks(): list<array{label: string, url: string}>`: `portal.home`, `portal.posts.index`, `portal.albums.index`, dan halaman
|   statis terbit pertama (`portal.pages.show`), semuanya dari `BuildThemePreviewUrl` untuk `selectedSlug`.
| - Di Situs > Tampilan dan Katalog tema, tema `source = local` memakai label `pondok.theme_editor.source_local` ("Buatan sendiri").
*/

beforeEach(function () {
    kontenBoot();
    themeSandbox();
    themeInstalledSandbox();
});

/** Tema `lokal-ubah` ber-css terpasang (token hex --pt-fg/--pt-bg/--pt-accent; pasangan kontras fg/bg). */
function teLocal(string $slug = 'lokal-ubah'): array
{
    $pkg = themePkg(['slug' => $slug]);
    themePkgPlace($pkg);

    return $pkg;
}

function tePage(string $role = 'super-admin', ?string $slug = 'lokal-ubah')
{
    test()->actingAs(kontenUser($role));

    return Livewire::test(ThemeEditor::class)->set('selectedSlug', $slug);
}

function teInstalled(string $slug = 'lokal-ubah'): array
{
    return json_decode((string) themeInstalledFile($slug), true);
}

function teRatio(string $fg, string $bg): string
{
    return __('pondok.theme_editor.contrast_ratio', ['ratio' => number_format(BuildColorScale::contrast($fg, $bg), 1, ',', '')]);
}

it('AC-1: only manage:themes with the Konten module on can open the page; others get 403 and no menu item', function () {
    teLocal();

    foreach (['admin-sekolah', 'admin-konten', 'bendahara', 'admin-psb', 'kesantrian', 'musyrif', 'petugas-pos', 'wali'] as $role) {
        $this->actingAs(kontenUser($role))->get(ThemeEditor::getUrl())->assertForbidden();
        expect(situsNavigationLabels(kontenUser($role)))->not->toContain(ThemeEditor::getNavigationLabel());
    }

    $this->actingAs(kontenUser('super-admin'))->get(ThemeEditor::getUrl())->assertOk();
    expect(ThemeEditor::getModule())->toBe('konten')
        ->and(situsNavigationLabels(kontenUser('super-admin')))->toContain(ThemeEditor::getNavigationLabel());

    Setting::set('modules.konten.enabled', false);
    $before = themeInstalledTree();

    $this->actingAs(kontenUser('super-admin'))->get(ThemeEditor::getUrl())->assertForbidden();

    expect(situsNavigationLabels(kontenUser('super-admin')))->not->toContain(ThemeEditor::getNavigationLabel())
        ->and(themeInstalledTree())->toBe($before);
});

it('AC-1: a role without manage:themes cannot mount the component directly either', function () {
    test()->actingAs(kontenUser('admin-sekolah'));

    Livewire::test(ThemeEditor::class)->assertForbidden();
});

it('loads a local theme into the form: ColorPicker tokens and layout for a css theme, no upload component', function () {
    $pkg = teLocal();

    $page = tePage()
        ->assertSet('data.name', $pkg['manifest']['name'])
        ->assertSet('data.tokens.--pt-fg', '#1a2b1c')
        ->assertSet('data.tokens.--pt-bg', '#fbf7ee')
        ->assertSet('data.tokens.--pt-accent', '#b4531a')
        ->assertSet('data.layout.0.component', $pkg['manifest']['home']['layout'][0]['component']);

    expect(collect($page->instance()->getForm('form')->getFlatComponents())->contains(fn ($component) => $component instanceof FileUpload))->toBeFalse();
});

it('only offers the palette, surface and font selects for a theme without css', function () {
    themeInstalledWrite('lokal-polos', [...themeBaseManifest(), 'palette' => 'hijau', 'surface' => 'krem', 'font' => 'lora']);

    tePage('super-admin', 'lokal-polos')
        ->assertSet('data.palette', 'hijau')
        ->assertSet('data.surface', 'krem')
        ->assertSet('data.font', 'lora')
        ->assertSet('data.tokens', []);
});

it('refuses to load a theme that is not lokal-*: official, installed, or missing', function () {
    themePkgPlace(themePkg(['slug' => 'zz-terpasang']));
    $before = themeInstalledTree();

    foreach (['default', 'zz-terpasang', 'lokal-tidak-ada'] as $slug) {
        tePage('super-admin', $slug)->assertSet('selectedSlug', null);
    }

    expect(themeInstalledTree())->toBe($before);
});

it('AC-5: shows the contrast ratio of every assets.contrast pair live, before saving', function () {
    $pkg = teLocal();
    $before = themeInstalledTree();

    $page = tePage()
        ->assertSee(teRatio('#1a2b1c', '#fbf7ee'))
        ->set('data.tokens.--pt-fg', '#000000')
        ->assertSee(teRatio('#000000', '#fbf7ee'));

    $rows = $page->instance()->contrastRows();

    expect($rows)->toHaveCount(count($pkg['manifest']['assets']['contrast']))
        ->and($rows[0])->toMatchArray(['fg' => '--pt-fg', 'bg' => '--pt-bg', 'size' => 'normal', 'passes' => true])
        ->and($rows[0]['ratio'])->toEqualWithDelta(BuildColorScale::contrast('#000000', '#fbf7ee'), 0.01)
        ->and(themeInstalledTree())->toBe($before, 'menghitung kontras tidak menulis apa pun');

    $page->set('data.tokens.--pt-fg', '#f5f1e6');

    expect($page->instance()->contrastRows()[0]['passes'])->toBeFalse();
    expect(__('pondok.theme_editor.contrast_ratio', ['ratio' => '1,0']))->not->toBe('pondok.theme_editor.contrast_ratio');
});

it('AC-5: rejects a token change that drops contrast below the threshold with a notification and leaves the theme unchanged', function () {
    teLocal();
    $before = themeInstalledTree();

    tePage()
        ->set('data.tokens.--pt-fg', '#f5f1e6')
        ->call('save')
        ->assertNotified(__('pondok.theme_editor.notifications.failed'));

    expect(themeInstalledTree())->toBe($before)
        ->and(teInstalled()['version'])->toBe('1.0.0');
});

it('saves tokens and layout through the action: version goes up and the old manifest becomes previous.json', function () {
    $pkg = teLocal();
    $old = themeInstalledFile('lokal-ubah');
    $layout = array_reverse($pkg['manifest']['home']['layout']);

    tePage()
        ->set('data.tokens.--pt-accent', '#112233')
        ->set('data.layout', $layout)
        ->call('save')
        ->assertNotified(__('pondok.theme_editor.notifications.saved'));

    $manifest = teInstalled();
    $css = (string) file_get_contents(themeInstalledSandbox().'/lokal-ubah/assets/'.substr(hash('sha256', (string) themeInstalledFile('lokal-ubah')), 0, 12).'/theme.css');

    expect($manifest['version'])->toBe('1.0.1')
        ->and(array_column($manifest['home']['layout'], 'component'))->toBe(array_column($layout, 'component'))
        ->and($css)->toContain('--pt-accent:#112233;')
        ->and(themeInstalledFile('lokal-ubah', 'previous.json'))->toBe($old)
        ->and(kontenActivities()->where('event', 'edited'))->toHaveCount(1);
});

it('saves name and description edits', function () {
    teLocal();

    tePage()->set('data.name', 'Nama Baru Saya')->set('data.description', 'Deskripsi baru.')->call('save');

    expect(teInstalled())->toMatchArray(['name' => 'Nama Baru Saya', 'description' => 'Deskripsi baru.']);
});

it('does not let the page save a theme that is active, not lokal-*, or not selected', function () {
    teLocal('lokal-aktif');
    themePkgPlace(themePkg(['slug' => 'zz-terpasang']));
    themeActivate('lokal-aktif');
    $before = themeInstalledTree();

    tePage('super-admin', 'lokal-aktif')->set('data.tokens.--pt-accent', '#112233')->call('save')
        ->assertNotified(__('pondok.theme_editor.notifications.failed'));

    tePage('super-admin', 'zz-terpasang')->set('data.name', 'Ganti')->call('save');
    tePage('super-admin', null)->set('data.name', 'Ganti')->call('save');

    expect(themeInstalledTree())->toBe($before);
});

it('duplicates a valid source theme from the header action into lokal-* and selects it', function () {
    teLocal('lokal-sumber');

    $page = tePage('super-admin', null)
        ->callAction('duplicate', ['source' => 'lokal-sumber', 'name' => 'Salinan Saya'])
        ->assertHasNoActionErrors()
        ->assertSet('selectedSlug', 'lokal-salinan-saya');

    expect(teInstalled('lokal-salinan-saya')['version'])->toBe('1.0.0')
        ->and(themeInstalledFile('lokal-sumber'))->not->toBeNull()
        ->and($page->get('data.name'))->toBe('Salinan Saya');

    $page->callAction('duplicate', ['source' => 'lokal-sumber', 'name' => 'Salinan Saya'])
        ->assertNotified(__('pondok.theme_editor.notifications.failed'));
});

it('lets the duplicate source be an official theme', function () {
    tePage('super-admin', null)
        ->callAction('duplicate', ['source' => 'default', 'name' => 'Dari Bawaan'])
        ->assertHasNoActionErrors()
        ->assertSet('selectedSlug', 'lokal-dari-bawaan');

    expect(teInstalled('lokal-dari-bawaan')['version'])->toBe('1.0.0');
});

it('builds signed preview links to /, /berita, /galeri and the first static page for the selected theme', function () {
    teLocal();
    StaticPage::factory()->published()->create(['slug' => 'tentang-tema']);

    $links = tePage()->instance()->previewLinks();
    $appUrl = rtrim((string) config('app.url'), '/');

    expect($links)->toHaveCount(4);

    foreach ($links as $link) {
        expect($link['label'])->toBeString()->not->toBe('')
            ->and($link['url'])->toStartWith($appUrl)
            ->toContain('pratinjau-tema=lokal-ubah')
            ->toContain('signature=')
            ->and(URL::hasValidSignature(Request::create($link['url'])))->toBeTrue($link['url']);
    }

    expect(implode(' ', array_column($links, 'url')))->toContain('/berita')->toContain('/galeri')->toContain('tentang-tema');
});

it('AC-13: labels local themes as Buatan sendiri in Tampilan and in the catalog', function () {
    app(DuplicatePortalTheme::class)->handle('default', ['name' => 'Label Uji'], kontenUser('super-admin'));
    $this->actingAs(kontenUser('super-admin'));

    expect(__('pondok.theme_editor.source_local'))->toBe('Buatan sendiri');

    Livewire::test(Theme::class)->assertSee('Buatan sendiri')->assertSee('Label Uji');

    Livewire::test(ThemeCatalog::class)
        ->assertCanSeeTableRecords(['lokal-label-uji'])
        ->assertSee('Buatan sendiri');
});

it('takes every visible string from lang/id', function () {
    expectTranslatedKeys([
        'pondok.theme_editor.title',
        'pondok.theme_editor.contrast_ratio',
        'pondok.theme_editor.source_local',
        'pondok.theme_editor.notifications.saved',
        'pondok.theme_editor.notifications.failed',
    ]);

    teLocal();
    $html = tePage()->html();

    expect($html)->not->toMatch('/\bpondok\.theme_editor\.[a-z_.]+/')
        ->and($html)->not->toContain('Save')->not->toContain('Duplicate')->not->toContain('Preview');
});
