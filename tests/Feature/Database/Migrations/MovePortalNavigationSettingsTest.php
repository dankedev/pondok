<?php

use App\Models\Setting;
use App\Models\StaticPage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| F043 AC-1 dan AC-2. Kontrak: migrasi `2026_10_19_000000_move_portal_navigation_settings.php` (anonymous class,
| `up()` dan `down()`), ditulis dengan `DB::table` (JSON di-decode sendiri). Hasil `navigation_primary` hanya
| memuat key `type`, `label`, dan `page_id`; bandingkan sebagai subset lewat `navSlim()`.
*/

beforeEach(function () {
    portalBoot();
    Setting::set('modules.psb.enabled', false);
    $this->legacyKeys = ['portal.menu_items', 'portal.menu_show_register', 'portal.footer_links', 'portal.footer_quote_arabic', 'portal.footer_quote_translation', 'portal.footer_quote_source'];
});

/**
 * @param  array<string, mixed>  $item
 * @return array<string, mixed>
 */
function navSlim(array $item): array
{
    return array_filter(['type' => $item['type'], 'label' => $item['label'] ?? null, 'page_id' => $item['page_id'] ?? null], fn (mixed $value): bool => $value !== null);
}

/**
 * @return list<array<string, mixed>>
 */
function navStoredPrimary(): array
{
    return array_map('navSlim', profilRawGet('portal.navigation_primary'));
}

it('AC-1: migrates only the visible menu items, keeping labels, order and the missing builtin items', function () {
    navSeedPublicContent();
    $a = staticPage(['slug' => 'pengurus', 'title' => 'Pengurus Yayasan']);
    $b = staticPage(['slug' => 'tersembunyi', 'title' => 'Halaman Tersembunyi']);
    profilRawSet('portal.menu_items', [
        menuBuiltin('home'), menuPage($a, 'Pengurus'), menuBuiltin('news', false), menuPage($b, null, false),
        menuBuiltin('gallery'), menuPage(999999), menuBuiltin('home', false),
    ]);

    navMigration()->up();

    // `videos` dan `psb_info` belum tercantum: ditambahkan di akhir selama tampil < 8.
    expect(navStoredPrimary())->toEqual([
        ['type' => 'home'], ['type' => 'page', 'label' => 'Pengurus', 'page_id' => $a->id], ['type' => 'albums'], ['type' => 'videos'], ['type' => 'psb.info'],
    ])->and(profilRawHas('portal.menu_items'))->toBeFalse();

    app()->forgetScopedInstances();

    expect(navLabels(navResolve()['primary']))->toBe(['Beranda', 'Pengurus', 'Galeri', 'Video']);
});

it('AC-1: migrates a menu with more than eight visible items into the first eight visible ones', function () {
    $pages = collect(range(1, 10))->map(fn (int $i) => staticPage(['slug' => "p-{$i}", 'title' => "Halaman {$i}"]));
    profilRawSet('portal.menu_items', $pages->map(fn (StaticPage $page): array => menuPage($page))->all());

    navMigration()->up();

    $stored = navStoredPrimary();

    expect($stored)->toHaveCount(8)
        ->and(array_column($stored, 'page_id'))->toBe($pages->take(8)->pluck('id')->all());
});

it('AC-1: keeps the default menu when menu_items is null or missing', function (bool $rowExists) {
    if ($rowExists) {
        profilRawSet('portal.menu_items', null);
    }

    navMigration()->up();

    expect(profilRawHas('portal.navigation_primary'))->toBeFalse()
        ->and(profilRawHas('portal.menu_items'))->toBeFalse();
})->with(['null row' => [true], 'no row' => [false]]);

it('AC-1: leaves the header identical for a long page title (label limit of 30 stays in the portal)', function () {
    $long = staticPage(['slug' => 'panjang', 'title' => str_repeat('Panjang', 12)]);
    profilRawSet('portal.menu_items', [menuBuiltin('home'), menuPage($long)]);

    navMigration()->up();

    $labels = navLabels(navResolve()['primary']);

    expect(profilRawGet('portal.navigation_primary')[1]['label'])->toBeNull()
        ->and($labels[1])->toBe(Str::limit(str_repeat('Panjang', 12), 30));
});

it('AC-2: migrates footer links with the two system links after them', function () {
    $page = staticPage(['slug' => 'pengurus']);
    profilRawSet('portal.footer_links', [['label' => 'Berita', 'url' => '/berita'], ['label' => null, 'page_id' => $page->id], ['label' => 'PSB', 'url' => 'https://contoh.test/psb']]);

    navMigration()->up();

    $footer = profilRawGet('portal.navigation_footer');

    expect(array_map('navSlim', $footer))->toEqual([
        ['type' => 'url', 'label' => 'Berita'], ['type' => 'page', 'page_id' => $page->id], ['type' => 'url', 'label' => 'PSB'], ['type' => 'psb.status'], ['type' => 'letters.verify'],
    ])->and($footer[0]['url'])->toBe('/berita')->and($footer[2]['url'])->toBe('https://contoh.test/psb')
        ->and(profilRawHas('portal.footer_links'))->toBeFalse();
});

it('AC-2: migrates the register toggle and archives the footer quote', function () {
    profilRawSet('portal.menu_show_register', false);
    profilRawSet('portal.footer_quote_arabic', 'نص عربي للاختبار');
    profilRawSet('portal.footer_quote_translation', 'Terjemah uji');
    profilRawSet('portal.footer_quote_source', 'Sumber uji');

    navMigration()->up();

    expect(profilRawGet('portal.header_button'))->toBeFalse()
        ->and(profilRawGet('portal.archived_footer_quote'))->toEqual(['arabic' => 'نص عربي للاختبار', 'translation' => 'Terjemah uji', 'source' => 'Sumber uji'])
        ->and(DB::table('settings')->whereIn('key', $this->legacyKeys)->count())->toBe(0);
});

it('AC-2: writes no header button or quote archive when the old values are default or empty', function () {
    profilRawSet('portal.menu_show_register', true);
    profilRawSet('portal.footer_quote_arabic', '');

    navMigration()->up();

    expect(profilRawHas('portal.header_button'))->toBeFalse()
        ->and(profilRawHas('portal.archived_footer_quote'))->toBeFalse()
        ->and(profilRawHas('portal.navigation_footer'))->toBeFalse()
        ->and(DB::table('settings')->whereIn('key', $this->legacyKeys)->count())->toBe(0);
});

it('AC-2: is idempotent and never overwrites existing new keys', function () {
    profilRawSet('portal.menu_items', [menuBuiltin('home'), menuBuiltin('news')]);
    profilRawSet('portal.footer_links', [['label' => 'Berita', 'url' => '/berita']]);
    profilRawSet('portal.navigation_footer', [navItem('psb.status')]);
    navSeedPublicContent();

    navMigration()->up();
    $after = profilSettingsSnapshot();

    expect(profilRawGet('portal.navigation_footer'))->toEqual([navItem('psb.status')])
        ->and(navStoredPrimary()[0])->toEqual(['type' => 'home']);

    navMigration()->up();

    expect(profilSettingsSnapshot())->toBe($after);
});

it('AC-2: treats broken stored values as empty without throwing', function () {
    profilRawSet('portal.menu_items', 'RAW:{rusak');
    profilRawSet('portal.footer_links', 'RAW:"bukan-daftar"');
    profilRawSet('portal.menu_show_register', 'RAW:xx');

    navMigration()->up();

    expect(profilRawHas('portal.menu_items'))->toBeFalse()
        ->and(profilRawHas('portal.footer_links'))->toBeFalse()
        ->and(profilRawHas('portal.menu_show_register'))->toBeFalse()
        ->and(profilRawHas('portal.header_button'))->toBeFalse();
});

it('AC-2: rolls back to the legacy keys, dropping what cannot be mapped', function () {
    $page = staticPage(['slug' => 'pengurus']);
    $inGroup = staticPage(['slug' => 'dalam-grup']);
    profilRawSet('portal.navigation_primary', [
        navItem('home'), navGroup('Profil', [navPage($inGroup, 'Dalam Grup'), navItem('url', 'Luar', ['url' => 'https://contoh.test'])]),
        navPage($page, 'Pengurus'), navItem('posts'), navItem('category', null, ['category_id' => 1]),
    ]);
    profilRawSet('portal.navigation_footer', [navItem('url', 'Berita', ['url' => '/berita']), navPage($page), navItem('psb.status')]);
    profilRawSet('portal.header_button', false);
    profilRawSet('portal.archived_footer_quote', ['arabic' => 'نص', 'translation' => 'Terjemah', 'source' => 'Sumber']);

    navMigration()->down();

    $menu = profilRawGet('portal.menu_items');

    expect($menu)->toEqual([
        menuBuiltin('home'), menuPage($inGroup, 'Dalam Grup'), menuPage($page, 'Pengurus'), menuBuiltin('news'),
    ])->and(profilRawGet('portal.footer_links'))->toEqual([['label' => 'Berita', 'url' => '/berita'], ['label' => null, 'page_id' => $page->id]])
        ->and(profilRawGet('portal.menu_show_register'))->toBeFalse()
        ->and(profilRawGet('portal.footer_quote_arabic'))->toBe('نص')
        ->and(profilRawGet('portal.footer_quote_translation'))->toBe('Terjemah')
        ->and(profilRawGet('portal.footer_quote_source'))->toBe('Sumber')
        ->and(DB::table('settings')->whereIn('key', ['portal.navigation_primary', 'portal.navigation_footer', 'portal.header_button', 'portal.archived_footer_quote'])->count())->toBe(0);
});
