<?php

use App\Actions\Konten\UpdatePortalNavigation;
use App\Models\Setting;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;

/*
| F043 VERIFY: celah di luar test RED. Data rusak di settings (tulis langsung), validasi tepi,
| kategori bukan berita, dan migrasi bolak-balik.
*/

beforeEach(function () {
    portalBoot();
    $this->seed(RolePermissionSeeder::class);
    Setting::set('modules.psb.enabled', true);
    Setting::set('modules.dokumen.enabled', true);
});

it('GAP: the portal still renders when the stored navigation and footer values are junk', function (string $primary, string $footer, string $button, string $text) {
    profilRawSet('portal.navigation_primary', $primary);
    profilRawSet('portal.footer_widgets', $footer);
    profilRawSet('portal.header_button', $button);
    profilRawSet('portal.home_blocks', $text);
    app()->forgetScopedInstances();

    $this->get('/')->assertOk();
    $this->get('/psb')->assertOk();
})->with([
    'broken json' => ['RAW:{rusak', 'RAW:[[', 'RAW:nope', 'RAW:}{'],
    'scalars' => ['RAW:"teks"', 'RAW:5', 'RAW:"x"', 'RAW:7'],
    'rows of the wrong shape' => [
        'RAW:[1,"a",null,{"type":["home"]},{"type":"group","label":"G","children":"x"},{"type":"page","page_id":[1]},{"type":"group","label":"G","children":[1,{"type":"group","label":"D","children":[{"type":"home"}]}]}]',
        'RAW:[{"type":"group","label":"G","children":[{"type":"home"}]},{"type":"url","label":"X","url":5}]',
        'RAW:{"type":"url","label":"X","url":["a"]}',
        'RAW:{"title":["x"],"body":{"a":1}}',
    ],
]);

it('GAP: unsafe urls written straight into settings are never rendered (menu, group child, footer widget, button)', function () {
    $bad = fn (string $url): array => navItem('url', 'Jahat', ['url' => $url]);
    profilRawSet('portal.navigation_primary', [navItem('home'), $bad('javascript:alert(1)'), navGroup('G', [$bad('data:text/html,x'), $bad('//evil.test')])]);
    ftrSet([ftr('links', ['items' => [$bad('javascript:alert(2)')]])]);
    profilRawSet('portal.header_button', $bad('vbscript:x'));
    app()->forgetScopedInstances();

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('javascript:alert')->not->toContain('data:text/html')->not->toContain('evil.test')->not->toContain('vbscript:')
        ->and($html)->not->toContain('Jahat');
});

it('GAP: a category that is not a news category is never rendered even when stored by hand', function () {
    portalPost(['title' => 'Berita Uji']);
    $album = kontenCategory('album', 'Fasilitas Album', 'fasilitas-album');
    navSet([navItem('home'), navItem('category', null, ['category_id' => $album->id])]);

    expect(navLabels(navResolve()['primary']))->toBe(['Beranda']);
});

it('GAP: the update action rejects a non-news category and a label that is only markup', function (Closure $data, string $field) {
    $album = kontenCategory('album', 'Fasilitas Album', 'fasilitas-album');
    $data = $data($album);

    try {
        app(UpdatePortalNavigation::class)->handle($data, kontenUser('admin-konten'));
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toContain($field);

        return;
    }

    test()->fail("ValidationException pada {$field} diharapkan.");
})->with([
    'album category' => [fn ($c) => ['primary' => [navItem('category', null, ['category_id' => $c->id])]], 'primary.0.category_id'],
    'url label that is only markup' => [fn () => ['primary' => [navItem('url', '<b></b>', ['url' => '/berita'])]], 'primary.0.label'],
    'group label that is only a script tag' => [fn () => ['primary' => [navGroup('<script></script>', [navItem('home')])]], 'primary.0.label'],
    'type given as a list' => [fn () => ['primary' => [['type' => ['home']]]], 'primary.0.type'],
    'children given as text' => [fn () => ['primary' => [navItem('group', 'G', ['children' => 'home'])]], 'primary.0.children'],
]);

it('GAP: the footer widget action rejects tricky link urls and keeps nothing on failure', function () {
    ftrSave([ftr('social', ['heading' => 'Awal'])]);
    $before = profilSettingsSnapshot();

    foreach (['ftp://contoh.test/a', 'https:///a', "/a\t//evil.test"] as $url) {
        expect(fn () => ftrSave([ftr('links', ['items' => [navItem('url', 'X', ['url' => $url])]])]))->toThrow(ValidationException::class);
    }

    expect(profilSettingsSnapshot())->toBe($before);
});

it('GAP: migration drops footer links to missing pages and unsafe urls, and keeps the system links last', function () {
    $page = staticPage(['slug' => 'pengurus', 'title' => 'Pengurus']);
    profilRawSet('portal.footer_links', [
        ['label' => 'Hilang', 'page_id' => 999999], ['label' => 'Jahat', 'url' => 'javascript:alert(1)'],
        ['label' => 'Tanpa URL'], 'bukan-array', ['label' => 'Pengurus', 'page_id' => $page->id], ['label' => 'Luar', 'url' => 'https://contoh.test/a'],
    ]);

    navMigration()->up();

    expect(array_map(fn (array $item): array => array_filter(['type' => $item['type'], 'label' => $item['label'] ?? null, 'page_id' => $item['page_id'] ?? null], fn (mixed $value): bool => $value !== null), profilRawGet('portal.navigation_footer')))->toBe([
        ['type' => 'page', 'label' => 'Pengurus', 'page_id' => $page->id], ['type' => 'url', 'label' => 'Luar'], ['type' => 'psb.status'], ['type' => 'letters.verify'],
    ]);
});

it('GAP: down flattens groups, drops duplicates and urls from the menu, and caps the legacy menu at eight', function () {
    $pages = collect(range(1, 6))->map(fn (int $i) => staticPage(['slug' => "p-{$i}", 'title' => "P{$i}"]));
    profilRawSet('portal.navigation_primary', [
        navItem('home'), navItem('url', 'Luar', ['url' => '/berita']), navItem('category', null, ['category_id' => 1]),
        navGroup('G', [navPage($pages[0]), navItem('home'), navPage($pages[1]), navPage($pages[2]), navPage($pages[3])]),
        navPage($pages[4]), navPage($pages[5]), navItem('videos'), navItem('albums'),
    ]);

    navMigration()->down();

    $menu = profilRawGet('portal.menu_items');

    expect($menu)->toHaveCount(8)
        ->and(array_column($menu, 'type'))->not->toContain('url')->not->toContain('group')
        ->and(collect($menu)->where('type', 'builtin')->pluck('key')->all())->toBe(['home', 'videos'])
        ->and(collect($menu)->pluck('visible')->unique()->all())->toBe([true]);
});

it('GAP: the navigation query runs without a FROM clause only as scalar subqueries (portable SQL)', function () {
    portalPost(['title' => 'Berita Uji']);
    navSet([navItem('posts'), navItem('albums'), navItem('videos')]);

    $queries = collect(recordedQueries(fn () => navResolve()))->pluck('sql')->filter(fn (string $sql): bool => str_starts_with($sql, 'select (select'));

    expect($queries)->toHaveCount(1)
        ->and($queries->first())->not->toContain('`')->not->toContain('dual')->not->toContain('::')
        ->and(substr_count($queries->first(), ' as '))->toBe(3);
});
