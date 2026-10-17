<?php

use App\Filament\Clusters\InstitutionProfile\Pages\Navigation;
use App\Filament\Resources\StaticPages\Pages\EditStaticPage;
use App\Models\Setting;
use App\Models\StaticPage;
use Livewire\Livewire;

/*
| F043 AC-9 (UI) dan AC-13. Kontrak halaman: `App\Filament\Clusters\InstitutionProfile\Pages\Navigation`
| (slug `navigation`, /admin/institution-profile/navigation), tombol simpan memanggil `save`. State form:
| - `data.primary`: repeater baris `{type, label, page_id, category_id, url, children}` (anak grup: repeater yang sama tanpa tipe `group`);
| - `data.footer`: repeater baris yang sama tanpa grup;
| - `data.header_button`: `{type, label, page_id, category_id, url}` dengan `type = 'none'` untuk "Tanpa tombol".
| Form diisi dari daftar efektif (bawaan dimaterialisasi). Aksi halaman statis: `addToPrimaryNavigation`
| ("Tambahkan ke menu utama"), tampil dan boleh dipanggil hanya untuk yang punya `view:school-profile`.
*/

beforeEach(function () {
    $this->f = kontenBoot(withPages: true);
    Setting::set('modules.psb.enabled', false);
});

/**
 * @return list<array<string, mixed>>
 */
function navRows(mixed $state): array
{
    return array_values($state ?? []);
}

it('AC-13: lets the profile roles open the navigation page and forbids the rest', function (string $role, bool $allowed) {
    $user = kontenUser($role);

    $this->actingAs($user)->get('/admin/institution-profile/navigation')->assertStatus($allowed ? 200 : 403);

    if (! $allowed && $role !== 'wali') {
        expect(kontenNavigationText($user))->not->toContain('Profil lembaga');
    }
})->with([
    ['super-admin', true], ['admin-sekolah', true], ['admin-konten', true],
    ['bendahara', false], ['admin-psb', false], ['kesantrian', false], ['musyrif', false], ['petugas-pos', false], ['wali', false],
]);

it('AC-13: the old menu page is gone', function () {
    $this->actingAs(kontenUser('super-admin'))->get('/admin/institution-profile/menu')->assertNotFound();
    expect(class_exists('App\Filament\Clusters\InstitutionProfile\Pages\Menu'))->toBeFalse();
});

it('AC-13: fills the form with the effective default navigation when nothing is saved', function () {
    $this->actingAs(kontenUser('admin-konten'));
    $profil = StaticPage::findBySlug('profil');
    $kontak = StaticPage::findBySlug('kontak');

    $component = Livewire::test(Navigation::class);
    $primary = navRows($component->get('data.primary'));

    expect(array_column($primary, 'type'))->toBe(['home', 'page', 'posts', 'albums', 'videos', 'psb.info', 'page'])
        ->and([$primary[1]['page_id'], $primary[6]['page_id']])->toBe([$profil->id, $kontak->id])
        ->and(array_column(navRows($component->get('data.footer')), 'type'))->toBe(['psb.status', 'letters.verify'])
        ->and($component->get('data.header_button.type'))->toBe('psb.register');
});

it('AC-13: fills the form from the saved navigation, including groups and no button', function () {
    $this->actingAs(kontenUser('admin-konten'));
    $page = staticPage(['slug' => 'pengurus']);
    navSet([navItem('home'), navGroup('Profil', [navPage($page, 'Pengurus'), navItem('url', 'Luar', ['url' => 'https://contoh.test'])])], [navItem('url', 'Berita', ['url' => '/berita'])], false);

    $component = Livewire::test(Navigation::class);
    $primary = navRows($component->get('data.primary'));
    $children = navRows($primary[1]['children']);

    expect($primary[1])->toMatchArray(['type' => 'group', 'label' => 'Profil'])
        ->and($children[0])->toMatchArray(['type' => 'page', 'page_id' => $page->id, 'label' => 'Pengurus'])
        ->and($children[1])->toMatchArray(['type' => 'url', 'label' => 'Luar', 'url' => 'https://contoh.test'])
        ->and(navRows($component->get('data.footer'))[0])->toMatchArray(['type' => 'url', 'label' => 'Berita', 'url' => '/berita'])
        ->and($component->get('data.header_button.type'))->toBe('none');
});

it('AC-13: saves through the form by calling the action', function () {
    $this->actingAs(kontenUser('admin-konten'));
    $page = staticPage(['slug' => 'pengurus']);
    $category = kontenCategory('post', 'Pengumuman');

    Livewire::test(Navigation::class)
        ->fillForm([
            'primary' => [
                ['type' => 'home', 'label' => ''],
                ['type' => 'group', 'label' => 'Profil', 'children' => [['type' => 'page', 'page_id' => $page->id, 'label' => ''], ['type' => 'category', 'category_id' => $category->id, 'label' => '']]],
                ['type' => 'url', 'label' => 'Situs', 'url' => 'https://contoh.test'],
            ],
            'footer' => [['type' => 'url', 'label' => 'Berita', 'url' => '/berita']],
            'header_button' => ['type' => 'none'],
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Setting::get('portal.navigation_primary'))->toEqual([
        navItem('home'), navGroup('Profil', [navPage($page), navItem('category', null, ['category_id' => $category->id])]), navItem('url', 'Situs', ['url' => 'https://contoh.test']),
    ])->and(Setting::get('portal.navigation_footer'))->toEqual([navItem('url', 'Berita', ['url' => '/berita'])])
        ->and(Setting::get('portal.header_button'))->toBeFalse()
        ->and(kontenActivities()->where('description', 'portal_navigation_updated'))->toHaveCount(1);
});

it('AC-13: shows nested row errors on the right field and saves nothing', function () {
    $this->actingAs(kontenUser('admin-konten'));

    $component = Livewire::test(Navigation::class)
        ->fillForm([
            'primary' => [['type' => 'group', 'label' => 'Profil', 'children' => [['type' => 'url', 'label' => 'X', 'url' => '//evil.test']]]],
            'footer' => [['type' => 'url', 'label' => 'Y', 'url' => 'javascript:x']],
            'header_button' => ['type' => 'none'],
        ])
        ->call('save');
    $keys = $component->errors()->keys();

    expect(collect($keys)->contains(fn (string $key): bool => preg_match('/^data\.primary\.[^.]+\.children\.[^.]+\.url$/', $key) === 1))->toBeTrue()
        ->and(collect($keys)->contains(fn (string $key): bool => preg_match('/^data\.footer\.[^.]+\.url$/', $key) === 1))->toBeTrue()
        ->and(Setting::query()->whereIn('key', ['portal.navigation_primary', 'portal.navigation_footer', 'portal.header_button'])->count())->toBe(0);
});

it('AC-13: shows an error, not an exception, when more than eight root items are added', function () {
    $this->actingAs(kontenUser('admin-konten'));

    $component = Livewire::test(Navigation::class)
        ->fillForm(['primary' => array_fill(0, 9, ['type' => 'home', 'label' => '']), 'footer' => [], 'header_button' => ['type' => 'none']])
        ->call('save');

    expect(collect($component->errors()->keys())->contains(fn (string $key): bool => $key === 'data.primary' || str_starts_with($key, 'data.primary.')))->toBeTrue()
        ->and(Setting::get('portal.navigation_primary'))->toBeNull();
});

it('AC-13: the form states that Info PSB appears once its page exists, and renders no raw translation keys', function () {
    $html = $this->actingAs(kontenUser('super-admin'))->get('/admin/institution-profile/navigation')->assertOk()->getContent();

    expect($html)->not->toMatch('/\bpondok\.[a-z_]+\.[a-z_]+/')->toContain('Info PSB');
    preg_match('#<form\b.*?</form>#is', $html, $form);
    preg_match_all('/placeholder="([^"]*)"/i', $form[0] ?? '', $placeholders);

    expect($form)->not->toBeEmpty()
        ->and(html_entity_decode(strip_tags($form[0] ?? '').' '.implode(' ', $placeholders[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))->not->toMatch('/\p{Arabic}/u')->not->toMatch('/bismillah|assalamu|ahlan/i');
});

it('AC-9: the edit-page action appends the page to the primary menu and keeps the other default items', function () {
    $this->actingAs(kontenUser('admin-konten'));
    $page = staticPage(['slug' => 'pengurus', 'title' => 'Pengurus']);

    Livewire::test(EditStaticPage::class, ['record' => $page->getRouteKey()])
        ->assertActionVisible('addToPrimaryNavigation')
        ->callAction('addToPrimaryNavigation')
        ->assertNotified();

    $primary = Setting::get('portal.navigation_primary');

    expect(array_column($primary, 'type'))->toBe(['home', 'page', 'posts', 'albums', 'videos', 'psb.info', 'page', 'page'])
        ->and(end($primary)['page_id'])->toBe($page->id);
});

it('AC-9: the action says so when the page is already listed and refuses when the menu is full, writing nothing', function () {
    $this->actingAs(kontenUser('admin-konten'));
    $page = staticPage(['slug' => 'pengurus']);
    $other = staticPage(['slug' => 'lain']);

    navSet([navItem('home'), navPage($page)]);
    $before = profilSettingsSnapshot();
    Livewire::test(EditStaticPage::class, ['record' => $page->getRouteKey()])->callAction('addToPrimaryNavigation')->assertNotified();

    expect(profilSettingsSnapshot())->toBe($before);

    navSet(array_fill(0, 8, navItem('home')));
    $before = profilSettingsSnapshot();
    Livewire::test(EditStaticPage::class, ['record' => $other->getRouteKey()])->callAction('addToPrimaryNavigation')->assertNotified();

    expect(profilSettingsSnapshot())->toBe($before);
});

it('AC-9: the action is hidden and forbidden for a role that may edit pages but lacks view:school-profile', function () {
    $user = kontenUser('bendahara');
    $user->givePermissionTo(['view-any:static-page', 'view:static-page', 'update:static-page']);
    $page = staticPage(['slug' => 'pengurus']);
    navSet([navItem('home')]);
    $before = profilSettingsSnapshot();
    $this->actingAs($user);

    expect($user->can('view:school-profile'))->toBeFalse();

    $component = Livewire::test(EditStaticPage::class, ['record' => $page->getRouteKey()])->assertActionHidden('addToPrimaryNavigation');

    try {
        $component->callAction('addToPrimaryNavigation');
    } catch (Throwable) {
        // ditolak (403) atau diabaikan karena tersembunyi: keduanya sah
    }

    expect(profilSettingsSnapshot())->toBe($before);
});
