<?php

use App\Filament\Clusters\InstitutionProfile\Pages\Menu;
use App\Models\Setting;
use App\Models\StaticPage;
use Illuminate\Foundation\Vite;
use Livewire\Livewire;

/*
| F037 AC-35 dan AC-27b/AC-27a (sisi editor). Kontrak halaman Menu:
| `App\Filament\Clusters\InstitutionProfile\Pages\Menu` (slug `menu`, /admin/institution-profile/menu),
| state `data.items` (repeater; baris berkunci `type`, `key` atau `page_id`, `label`, `visible`)
| dan `data.show_register`; tombol simpan memanggil `save` seperti halaman cluster lain.
*/

beforeEach(function () {
    $this->f = kontenBoot(withPages: true);
    Setting::set('modules.psb.enabled', false);
});

/**
 * Baris repeater sebagai daftar berindeks.
 *
 * @return list<array<string, mixed>>
 */
function menuRows($component): array
{
    return array_values($component->get('data.items'));
}

/**
 * @param  array<string, mixed>  $row
 */
function menuRowId(array $row): string
{
    return $row['type'] === 'builtin' ? $row['key'] : 'page:'.$row['page_id'];
}

it('AC-35: lets the profile roles open the menu page and forbids the rest', function (string $role, bool $allowed) {
    $user = kontenUser($role);

    $this->actingAs($user)->get('/admin/institution-profile/menu')->assertStatus($allowed ? 200 : 403);

    if (! $allowed && $role !== 'wali') {
        expect(kontenNavigationText($user))->not->toContain('Profil lembaga');
    }
})->with([
    ['super-admin', true], ['admin-sekolah', true], ['admin-konten', true],
    ['bendahara', false], ['admin-psb', false], ['kesantrian', false], ['musyrif', false], ['petugas-pos', false], ['wali', false],
]);

it('AC-35: lists every builtin item and every page, with no add or delete controls', function () {
    $this->actingAs(kontenUser('admin-konten'));
    $draft = staticPage(['slug' => 'rancangan', 'title' => 'Halaman Rancangan', 'status' => 'draft']);

    $component = Livewire::test(Menu::class);
    $ids = array_map('menuRowId', menuRows($component));

    expect($ids)->toContain('home', 'news', 'gallery', 'videos', 'psb_info', 'page:'.$draft->id)
        ->and($ids)->toContain(...array_map(fn (string $slug): string => 'page:'.StaticPage::findBySlug($slug)->id, StaticPage::SYSTEM_SLUGS));

    $html = $this->get('/admin/institution-profile/menu')->assertOk()->getContent();

    expect($html)->not->toMatch('/\bpondok\.[a-z_]+\.[a-z_]+/')
        ->toContain(__('pondok.portal.nav.home'))->toContain(__('pondok.portal.nav.news'))->toContain('Halaman Rancangan')
        ->and(__('pondok.portal.nav.psb_info'))->toBe('Info PSB');
    $component->assertSchemaComponentExists('items', checkComponentUsing: fn ($repeater): bool => ! $repeater->isAddable() && ! $repeater->isDeletable() && $repeater->isReorderable());
});

it('AC-27b: shows the default header order when the stored menu is null and saves it unchanged', function () {
    $this->actingAs(kontenUser('admin-konten'));
    staticPage(['slug' => 'beta', 'title' => 'Beta']);
    staticPage(['slug' => 'alfa', 'title' => 'Alfa']);
    $this->withoutVite();
    (fn () => $this->buildDirectory = 'build-tidak-ada-saat-test')->call(app(Vite::class));
    portalContactData();

    $before = portalNavTexts($this->get('/')->assertOk()->getContent());

    $component = Livewire::test(Menu::class);
    $rows = menuRows($component);

    expect(array_map('menuRowId', array_slice($rows, 0, 7)))->toBe([
        'home', 'page:'.StaticPage::findBySlug('profil')->id, 'news', 'gallery', 'videos', 'psb_info', 'page:'.StaticPage::findBySlug('kontak')->id,
    ])->and(collect(array_slice($rows, 0, 7))->pluck('visible')->unique()->all())->toBe([true])
        ->and(collect(array_slice($rows, 7))->pluck('visible')->unique()->all())->toBe([false])
        ->and(array_slice($rows, 7, 2))->each->toMatchArray(['type' => 'page']);
    expect(StaticPage::query()->whereKey(array_column(array_slice($rows, 7, 2), 'page_id'))->orderBy('title')->pluck('slug')->all())->toBe(['alfa', 'beta'])
        ->and(array_column(array_slice($rows, 7, 2), 'page_id'))->toBe(StaticPage::query()->whereIn('slug', ['alfa', 'beta'])->orderBy('title')->pluck('id')->all());

    $component->call('save')->assertHasNoFormErrors();

    expect(Setting::get('portal.menu_items'))->toHaveCount(count($rows))
        ->and(portalNavTexts($this->get('/')->getContent()))->toBe($before);
});

it('AC-27a: appends a new builtin item hidden when eight are already visible, and saves without error', function () {
    $this->actingAs(kontenUser('admin-konten'));
    $pages = collect(range(1, 4))->map(fn (int $i) => staticPage(['slug' => "p-{$i}", 'title' => "Halaman {$i}"]));
    menuSet([menuBuiltin('home'), menuBuiltin('news'), menuBuiltin('gallery'), menuBuiltin('videos'), ...$pages->map(fn ($p): array => menuPage($p))->all()]);

    $component = Livewire::test(Menu::class);
    $psb = collect(menuRows($component))->firstWhere('key', 'psb_info');

    expect($psb)->not->toBeNull()->and($psb['visible'])->toBeFalse();

    $component->call('save')->assertHasNoFormErrors()->assertNotified();
});

it('AC-27a: appends a new builtin item visible when fewer than eight are visible', function () {
    $this->actingAs(kontenUser('admin-konten'));
    $pages = collect(range(1, 3))->map(fn (int $i) => staticPage(['slug' => "p-{$i}", 'title' => "Halaman {$i}"]));
    menuSet([menuBuiltin('home'), menuBuiltin('news'), menuBuiltin('gallery'), menuBuiltin('videos'), ...$pages->map(fn ($p): array => menuPage($p))->all()]);

    $psb = collect(menuRows(Livewire::test(Menu::class)))->firstWhere('key', 'psb_info');

    expect($psb['visible'])->toBeTrue();
});

it('AC-35: reorders, hides an item and saves the menu', function () {
    $this->actingAs(kontenUser('admin-konten'));
    $a = staticPage(['slug' => 'a-halaman', 'title' => 'Halaman A']);
    menuSet([menuBuiltin('home'), menuBuiltin('news'), menuPage($a), menuBuiltin('gallery'), menuBuiltin('videos'), menuBuiltin('psb_info')]);

    $component = Livewire::test(Menu::class);
    $keyed = $component->get('data.items');
    $reordered = [];
    $byId = [];
    foreach ($keyed as $uuid => $row) {
        $byId[menuRowId($row)] = [$uuid, $row];
    }
    foreach (['page:'.$a->id, 'home', 'news', 'gallery'] as $id) {
        [$uuid, $row] = $byId[$id];
        $reordered[$uuid] = $row;
        unset($byId[$id]);
    }
    foreach ($byId as $id => [$uuid, $row]) {
        $reordered[$uuid] = $row;
    }
    foreach ($reordered as $uuid => $row) {
        if ($row['type'] === 'builtin' && $row['key'] === 'news') {
            $reordered[$uuid]['visible'] = false;
        }
    }

    $component->set('data.items', $reordered)->set('data.show_register', false)->call('save')->assertHasNoFormErrors()->assertNotified();

    $stored = Setting::get('portal.menu_items');

    expect(array_slice(array_map(fn (array $i): string => $i['type'] === 'page' ? 'page:'.$i['page_id'] : $i['key'], $stored), 0, 4))->toBe(['page:'.$a->id, 'home', 'news', 'gallery'])
        ->and(collect($stored)->firstWhere('key', 'news')['visible'])->toBeFalse()
        ->and(Setting::get('portal.menu_show_register'))->toBeFalse();
});

it('AC-35: shows an error when more than eight items are visible, without an exception', function () {
    $this->actingAs(kontenUser('admin-konten'));
    foreach (range(1, 6) as $i) {
        staticPage(['slug' => "p-{$i}", 'title' => "Halaman {$i}"]);
    }

    $component = Livewire::test(Menu::class);
    $rows = $component->get('data.items');
    foreach ($rows as $uuid => $row) {
        $rows[$uuid]['visible'] = true;
    }

    $component->set('data.items', $rows)->call('save');

    expect(kontenSeeMessage($component, 'menu_too_many'))->toBeTrue()
        ->and(Setting::get('portal.menu_items'))->toBeNull();
});

it('AC-37: renders the menu page without content examples', function () {
    $html = $this->actingAs(kontenUser('super-admin'))->get('/admin/institution-profile/menu')->assertOk()->getContent();
    preg_match('#<form\b.*?</form>#is', $html, $form);
    preg_match_all('/placeholder="([^"]*)"/i', $form[0] ?? '', $placeholders);
    $visible = html_entity_decode(strip_tags($form[0] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8').' '.implode(' ', $placeholders[1]);

    expect($form)->not->toBeEmpty()->and($html)->not->toMatch('/\bpondok\.[a-z_]+\.[a-z_]+/')
        ->and($visible)->not->toMatch('/\p{Arabic}/u')->not->toMatch('/\bQS\b/')->not->toMatch('/bismillah|assalamu|ahlan/i');
});
