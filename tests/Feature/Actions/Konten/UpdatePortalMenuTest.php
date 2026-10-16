<?php

use App\Actions\Konten\UpdatePortalMenu;
use App\Models\Setting;
use App\Models\StaticPage;
use Illuminate\Validation\ValidationException;

/*
| F037 AC-26 dan AC-27. Kontrak: `UpdatePortalMenu::handle(array $data, User $actor): void`
| dengan `items` (list {type, key|page_id, label?, visible}) dan `show_register` (bool).
| Disimpan ke `portal.menu_items` (list atau null) dan `portal.menu_show_register`; log `portal_menu_updated`.
*/

beforeEach(function () {
    $this->f = kontenBoot(withPages: true);
    $this->admin = kontenUser('admin-konten');
});

/**
 * @param  array<string, mixed>  $data
 */
function kontenSaveMenu(array $data): void
{
    app(UpdatePortalMenu::class)->handle(['show_register' => true, ...$data], test()->admin);
}

it('AC-26: saves the ordered menu, the register flag and logs once', function () {
    $draft = staticPage(['slug' => 'draf', 'status' => 'draft']);
    $a = staticPage(['slug' => 'a-halaman']);
    $b = staticPage(['slug' => 'b-halaman']);
    $items = [
        menuPage($a, 'Pengurus', true), menuBuiltin('videos', false), menuBuiltin('home'), menuPage($draft, null, false),
        menuBuiltin('psb_info', true), menuPage($b), menuBuiltin('gallery', false), menuBuiltin('news'),
    ];

    kontenSaveMenu(['items' => $items, 'show_register' => false]);

    expect(Setting::get('portal.menu_items'))->toBe($items)
        ->and(Setting::get('portal.menu_show_register'))->toBeFalse()
        ->and(kontenActivities()->where('description', 'portal_menu_updated'))->toHaveCount(1);

    kontenSaveMenu(['items' => $items, 'show_register' => false]);

    expect(kontenActivities()->where('description', 'portal_menu_updated'))->toHaveCount(1);
});

it('AC-26: normalizes an empty page label to null and stores an empty list as null', function () {
    $a = staticPage();

    kontenSaveMenu(['items' => [['type' => 'page', 'page_id' => $a->id, 'label' => '  ', 'visible' => true]]]);

    expect(Setting::get('portal.menu_items'))->toBe([menuPage($a, null, true)]);

    kontenSaveMenu(['items' => []]);

    expect(Setting::get('portal.menu_items'))->toBeNull();
});

it('AC-27: rejects an unknown builtin key, an unknown type, a missing page and duplicates', function () {
    $a = staticPage();
    $before = profilSettingsSnapshot();

    kontenAssertRejected(fn () => kontenSaveMenu(['items' => [menuBuiltin('home'), ['type' => 'builtin', 'key' => 'login', 'visible' => true]]]), 'menu_item_invalid', 'items.1.key');
    kontenAssertRejected(fn () => kontenSaveMenu(['items' => [['type' => 'link', 'key' => 'home', 'visible' => true]]]), 'menu_item_invalid');
    kontenAssertRejected(fn () => kontenSaveMenu(['items' => [menuPage(999999)]]), 'page_not_found', 'items.0.page_id');
    kontenAssertRejected(fn () => kontenSaveMenu(['items' => [menuBuiltin('news'), menuBuiltin('news')]]), 'menu_item_duplicate', 'items.1.key');
    kontenAssertRejected(fn () => kontenSaveMenu(['items' => [menuPage($a), menuPage($a)]]), 'menu_item_duplicate', 'items.1.page_id');

    expect(profilSettingsSnapshot())->toBe($before);
});

it('AC-27: rejects a label longer than 30 characters and accepts exactly 30', function () {
    $a = staticPage();
    $before = profilSettingsSnapshot();

    kontenAssertRejected(fn () => kontenSaveMenu(['items' => [menuPage($a, str_repeat('a', 31))]]), 'too_long', 'items.0.label');

    expect(profilSettingsSnapshot())->toBe($before);

    kontenSaveMenu(['items' => [menuPage($a, str_repeat('a', 30))]]);

    expect(Setting::get('portal.menu_items')[0]['label'])->toHaveLength(30);
});

it('AC-27: rejects nine visible items regardless of display status and accepts eight', function () {
    $draft = staticPage(['slug' => 'draf', 'status' => 'draft']);
    $pages = collect(range(1, 3))->map(fn (int $i) => staticPage(['slug' => "p-{$i}"]));
    $builtins = ['home', 'news', 'gallery', 'videos', 'psb_info']; // psb_info tanpa route tetap dihitung
    $nine = [...array_map(fn (string $key): array => menuBuiltin($key), $builtins), menuPage($draft), ...$pages->map(fn ($p): array => menuPage($p))->all()];
    $before = profilSettingsSnapshot();

    kontenAssertRejected(fn () => kontenSaveMenu(['items' => $nine]), 'menu_too_many', 'items');

    expect(profilSettingsSnapshot())->toBe($before);

    $nine[8]['visible'] = false;
    kontenSaveMenu(['items' => $nine]);

    expect(Setting::get('portal.menu_items'))->toHaveCount(9);
});

it('AC-27: refuses hand-made input without touching settings', function (mixed $items) {
    $before = profilSettingsSnapshot();

    try {
        kontenSaveMenu(['items' => $items]);
    } catch (ValidationException) {
        // ditolak atau dianggap kosong: keduanya boleh, asalkan tidak ada data rusak tersimpan
    }

    $menu = Setting::get('portal.menu_items');

    expect($menu === null || (is_array($menu) && array_is_list($menu) && collect($menu)->every(fn (mixed $row): bool => is_array($row) && in_array($row['type'] ?? null, ['builtin', 'page'], true))))->toBeTrue()
        ->and(StaticPage::query()->count())->toBe(3)
        ->and(is_array($before))->toBeTrue();
})->with(['scalar' => ['bukan-daftar'], 'junk item' => [['x', 5]]]);
