<?php

use App\Actions\Konten\GetPortalFooter;
use App\Actions\Konten\GetSchoolProfile;
use App\Filament\Pages\Site\Footer;
use App\Models\Setting;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

/*
| F046 VERIFY: celah di luar test RED (migrasi dengan data tepi, data rusak di settings, batas 4 dengan widget
| tersembunyi, footer ringkas, jumlah query).
*/

beforeEach(function () {
    portalBoot();
    $this->seed(RolePermissionSeeder::class);
    Setting::set('school.name', "Ma'had Al-Kitab");
    Setting::set('modules.psb.enabled', false);
    Setting::set('modules.dokumen.enabled', false);
});

it('migrates a legacy footer link to a deleted page without loss, and the admin form can save the result', function () {
    ftrLegacy(['portal.navigation_footer' => [navItem('url', 'Berita', ['url' => '/berita']), navPage(99999, 'Halaman Hilang')]]);

    ftrMigration()->up();

    expect(collect(ftrStored())->firstWhere('type', 'links')['data']['items'])->toHaveCount(2);

    $this->actingAs(kontenUser('admin-konten'));
    Livewire\Livewire::test(Footer::class)->call('save')->assertHasNoFormErrors();

    expect(collect(ftrStored())->firstWhere('type', 'links')['data']['items'])->toEqual([navItem('url', 'Berita', ['url' => '/berita'])]);
});

it('migrates short text with CRLF line breaks into br without carriage returns and saves back unchanged', function () {
    ftrLegacy(['portal.footer_text' => ['title' => 'Jam', 'body' => "Baris satu\r\nBaris dua\rBaris tiga"]]);

    ftrMigration()->up();
    $widgets = ftrStored();
    $body = $widgets[0]['data']['body'];

    expect($body)->not->toContain("\r")->not->toContain("\n")->and(substr_count($body, '<br>'))->toBe(2);

    ftrSave($widgets);

    expect(ftrStored())->toEqual($widgets);
});

it('merges a short text without title or description over four candidates and keeps the body', function () {
    ftrLegacy(['portal.footer_description' => null, 'portal.footer_text' => ['title' => null, 'body' => 'Isi saja']]);

    ftrMigration()->up();
    $widgets = ftrStored();

    expect(array_column($widgets, 'type'))->toBe(['text', 'contacts', 'social', 'links'])
        ->and($widgets[0]['data']['body'])->toContain('Isi saja')->not->toContain('<strong>');

    ftrSave($widgets);

    expect(ftrStored())->toEqual($widgets);
});

it('migrates a short text with only a title and drops nothing that has content', function () {
    ftrLegacy(['portal.footer_text' => ['title' => 'Hanya Judul', 'body' => null], 'portal.footer_show_contacts' => false]);

    ftrMigration()->up();

    expect(array_column(ftrStored(), 'type'))->toBe(['text', 'social', 'links']);
    ftrSave(ftrStored());
});

it('migrates contacts and social switches stored as non-boolean values without crashing', function () {
    ftrLegacy(['portal.footer_show_contacts' => 0, 'portal.footer_show_social' => '0']);

    ftrMigration()->up();

    expect(ftrStored())->not->toBeEmpty();
    ftrSave(ftrStored());
});

it('rejects a fifth widget even when the extra or existing ones are hidden, and never truncates', function () {
    $stored = [ftr('contacts', ['heading' => 'Lama'])];
    ftrSave($stored);

    ftrAssertRejected([
        ftr('text', ['body' => '<p>a</p>', 'show_logo' => false], visible: false),
        ftr('contacts', [], visible: false),
        ftr('social', [], visible: false),
        ftr('links', ['items' => [navItem('url', 'A', ['url' => '/a'])]], visible: false),
        ftr('social', [], visible: false),
    ], ['footer_widgets'], 'lima widget tersembunyi');
});

it('does not crash and shows only safe widgets when stored settings are malformed', function () {
    DB::table('settings')->updateOrInsert(['key' => 'portal.footer_widgets'], ['value' => json_encode([
        'bukan array', ['type' => 'hero', 'visible' => true, 'data' => []], ['type' => 'links', 'visible' => true, 'data' => ['items' => 'x']],
        ['type' => 'text', 'visible' => true, 'data' => ['body' => str_repeat('a', 7000), 'show_logo' => false]],
        ['type' => 'text', 'visible' => true, 'data' => ['body' => '<p>Ke-5</p>', 'show_logo' => false]],
        ['type' => 'text', 'visible' => true, 'data' => ['body' => '<p>Ke-6</p>', 'show_logo' => false]],
        ['type' => 'text', 'visible' => true, 'data' => ['body' => '<p>Ke-7</p>', 'show_logo' => false]],
    ]), 'created_at' => now(), 'updated_at' => now()]);
    app()->forgetScopedInstances();

    $footer = profilFooter($this->get('/')->assertOk()->getContent());

    expect(substr_count($footer, 'Ke-'))->toBeLessThanOrEqual(4)->and($footer)->not->toContain('hero');
});

it('does not render a text widget body with an event handler or javascript url at read time', function () {
    ftrSet([ftr('text', ['body' => '<p onclick="x()">Aman</p><img src=x onerror=alert(1)><a href=" javascript:alert(1)">a</a><a href="data:text/html,x">b</a>', 'show_logo' => false])]);

    $footer = profilFooter($this->get('/')->assertOk()->getContent());

    expect($footer)->toContain('Aman')->not->toContain('onclick')->not->toContain('onerror')->not->toContain('javascript:')->not->toContain('data:text');
});

it('drops stored link urls with protocol-relative, backslash or control-character tricks', function () {
    ftrSet([ftr('links', ['items' => [
        navItem('url', 'A', ['url' => '/\\evil.test']), navItem('url', 'B', ['url' => "java\tscript:alert(1)"]),
        navItem('url', 'C', ['url' => 'JaVaScRiPt:alert(1)']), navItem('url', 'D', ['url' => 'vbscript:x']), navItem('url', 'Ok', ['url' => '/berita']),
    ]])]);

    $footer = profilFooter($this->get('/')->assertOk()->getContent());

    expect($footer)->toContain('href="/berita"')->not->toContain('evil.test')->not->toContain('script:');
});

it('compact links skip a hidden first links widget and fall back to empty when no links widget is usable', function () {
    ftrSet([
        ftr('links', ['items' => [navItem('url', 'Tersembunyi', ['url' => '/a'])]], visible: false),
        ftr('links', ['items' => [navItem('url', 'Kedua', ['url' => '/galeri'])]]),
    ]);
    app()->forgetScopedInstances();

    expect(array_column(app(GetPortalFooter::class)->handle(app(GetSchoolProfile::class)->handle())['compactLinks'], 'label'))->toBe(['Kedua']);

    ftrSet([ftr('text', ['body' => '<p>Teks</p>', 'show_logo' => false]), ftr('links', ['items' => [navPage(99999)]])]);
    $footer = profilFooter($this->get('/halaman-yang-tidak-ada')->assertNotFound()->getContent());

    expect($footer)->not->toBe('')->not->toContain('Teks')->toContain('Ma&#039;had Al-Kitab');
});

it('loads footer settings in one query and does not grow the pages queries with the number of links', function () {
    $pages = collect(range(1, 12))->map(fn (int $i) => staticPage(['slug' => "hal-uji-{$i}"]));
    ftrSet([ftr('links', ['items' => [navPage($pages[0])]])]);
    $few = recordedQueries(fn () => $this->get('/berita')->assertOk());

    ftrSet([ftr('links', ['items' => $pages->map(fn ($p) => navPage($p))->all()]), ftr('links', ['items' => [navPage($pages[1]), navPage($pages[2])]])]);
    $many = recordedQueries(fn () => $this->get('/berita')->assertOk());

    expect(queriesOnTable($many, 'pages'))->toBeLessThanOrEqual(queriesOnTable($few, 'pages'))
        ->and(queriesOnTable($many, 'settings'))->toBeLessThanOrEqual(queriesOnTable($few, 'settings'));
});
