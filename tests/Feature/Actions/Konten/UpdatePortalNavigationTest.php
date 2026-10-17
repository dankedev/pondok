<?php

use App\Actions\Konten\UpdatePortalNavigation;
use App\Models\Setting;
use Illuminate\Validation\ValidationException;

/*
| F043 AC-4 dan AC-5. Kontrak: `UpdatePortalNavigation::handle(array $data, User $actor): void` dengan
| `primary` (list Item), `footer` (list Item), `header_button` (Item | false | null).
| Disimpan ke `portal.navigation_primary`, `portal.navigation_footer`, `portal.header_button`; log `portal_navigation_updated`
| dengan `properties.changed` berisi key yang berubah. Input `null` = key disimpan `null`/dihapus (bawaan), `[]` = `[]`.
| Field galat memakai titik: `primary.2.page_id`, `primary.0.children.1.url`, `footer.3.label`, `header_button.type`.
*/

beforeEach(function () {
    $this->f = kontenBoot(withPages: true);
    $this->admin = kontenUser('admin-konten');
});

/**
 * @param  array<string, mixed>  $data
 */
function navSave(array $data): void
{
    app(UpdatePortalNavigation::class)->handle(['primary' => [], 'footer' => [], 'header_button' => null, ...$data], test()->admin);
}

/**
 * @param  array<string, mixed>  $data
 */
function navAssertRejected(array $data, string $field, ?string $messageContains = null): void
{
    $before = profilSettingsSnapshot();

    try {
        navSave($data);
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toContain($field);

        if ($messageContains !== null) {
            expect(implode(' ', array_merge(...array_values($exception->errors()))))->toContain($messageContains);
        }

        expect(profilSettingsSnapshot())->toBe($before);

        return;
    }

    test()->fail("ValidationException pada {$field} diharapkan, tetapi tidak dilempar.");
}

it('AC-4: saves every target type normalized, in input order, and logs once', function () {
    $draft = staticPage(['slug' => 'draf', 'status' => 'draft']);
    $page = staticPage(['slug' => 'pengurus']);
    $category = kontenCategory('post', 'Pengumuman');
    $primary = [
        navItem('home'), navPage($page, 'Pengurus'), navPage($draft), navItem('posts'), navItem('category', null, ['category_id' => $category->id]),
        navItem('albums'), navItem('videos'),
        navGroup('Profil', [navPage($page), navItem('psb.info'), navItem('psb.register'), navItem('psb.status'), navItem('letters.verify'), navItem('url', 'Luar', ['url' => 'https://contoh.test/x']), navItem('url', 'Dalam', ['url' => '/berita'])]),
    ];
    $footer = [navItem('psb.status'), navItem('url', 'Tautan', ['url' => '/berita']), navPage($page)];

    navSave(['primary' => $primary, 'footer' => $footer, 'header_button' => navItem('psb.register', 'Daftar Sekarang')]);

    expect(Setting::get('portal.navigation_primary'))->toEqual($primary)
        ->and(Setting::get('portal.navigation_footer'))->toEqual($footer)
        ->and(Setting::get('portal.header_button'))->toEqual(navItem('psb.register', 'Daftar Sekarang'))
        ->and(kontenActivities()->where('description', 'portal_navigation_updated'))->toHaveCount(1)
        ->and(array_keys(kontenActivities()->last()->properties['changed']))->toEqualCanonicalizing(['portal.navigation_primary', 'portal.navigation_footer', 'portal.header_button']);

    navSave(['primary' => $primary, 'footer' => $footer, 'header_button' => navItem('psb.register', 'Daftar Sekarang')]);

    expect(kontenActivities()->where('description', 'portal_navigation_updated'))->toHaveCount(1);
});

it('AC-4: logs only the keys that changed', function () {
    navSave(['primary' => [navItem('home')], 'footer' => [navItem('psb.status')]]);
    navSave(['primary' => [navItem('home'), navItem('posts')], 'footer' => [navItem('psb.status')]]);

    $logs = kontenActivities()->where('description', 'portal_navigation_updated');

    expect($logs)->toHaveCount(2)
        ->and(array_keys($logs->last()->properties['changed']))->toBe(['portal.navigation_primary']);
});

it('AC-4: normalizes blank labels to null, stores false for no button and keeps an empty list empty', function () {
    $page = staticPage();

    navSave(['primary' => [['type' => 'page', 'page_id' => $page->id, 'label' => '   ']], 'footer' => [], 'header_button' => false]);

    expect(Setting::get('portal.navigation_primary'))->toEqual([navPage($page)])
        ->and(Setting::get('portal.navigation_primary')[0]['label'])->toBeNull()
        ->and(Setting::get('portal.navigation_footer'))->toBe([])
        ->and(Setting::get('portal.header_button'))->toBeFalse();
});

it('AC-4: stores labels as plain text without markup and accepts exactly the label limits', function () {
    $page = staticPage();

    navSave(['primary' => [navItem('url', str_repeat('a', 30), ['url' => '/berita']), navPage($page, '<b>Tebal</b>')], 'footer' => [navItem('url', str_repeat('b', 60), ['url' => '/berita'])], 'header_button' => navItem('psb.register', str_repeat('c', 30))]);

    expect(Setting::get('portal.navigation_primary')[0]['label'])->toHaveLength(30)
        ->and(Setting::get('portal.navigation_primary')[1]['label'] ?? '')->not->toContain('<b>')
        ->and(Setting::get('portal.navigation_footer')[0]['label'])->toHaveLength(60)
        ->and(Setting::get('portal.header_button')['label'])->toHaveLength(30);
});

it('AC-5: rejects an invalid item with an error on the exact field and changes nothing', function (array $data, string $field) {
    navSave(['primary' => [navItem('home')]]);

    navAssertRejected($data, $field);
})->with([
    'unknown type' => [['primary' => [navItem('home'), navItem('bogus')]], 'primary.1.type'],
    'missing type' => [['primary' => [['label' => 'X']]], 'primary.0.type'],
    'page that does not exist' => [['primary' => [navItem('page', null, ['page_id' => 999999])]], 'primary.0.page_id'],
    'page without id' => [['primary' => [navItem('page')]], 'primary.0.page_id'],
    'page id as text' => [['primary' => [navItem('page', null, ['page_id' => 'abc'])]], 'primary.0.page_id'],
    'category that does not exist' => [['primary' => [navItem('category', null, ['category_id' => 999999])]], 'primary.0.category_id'],
    'javascript url' => [['primary' => [navItem('url', 'X', ['url' => 'javascript:alert(1)'])]], 'primary.0.url'],
    'data url' => [['primary' => [navItem('url', 'X', ['url' => 'data:text/html,x'])]], 'primary.0.url'],
    'protocol relative url' => [['primary' => [navItem('url', 'X', ['url' => '//evil.test'])]], 'primary.0.url'],
    'backslash url' => [['primary' => [navItem('url', 'X', ['url' => '/\\evil.test'])]], 'primary.0.url'],
    'relative url without slash' => [['primary' => [navItem('url', 'X', ['url' => 'berita'])]], 'primary.0.url'],
    'url over 255 characters' => [['primary' => [navItem('url', 'X', ['url' => 'https://contoh.test/'.str_repeat('a', 240)])]], 'primary.0.url'],
    'url without url' => [['primary' => [navItem('url', 'X')]], 'primary.0.url'],
    'url without a label' => [['primary' => [navItem('url', '', ['url' => '/berita'])]], 'primary.0.label'],
    'group without a label' => [['primary' => [navGroup('', [navItem('home')])]], 'primary.0.label'],
    'menu label over 30' => [['primary' => [navItem('url', str_repeat('a', 31), ['url' => '/berita'])]], 'primary.0.label'],
    'optional label over 30' => [['primary' => [navItem('posts', str_repeat('a', 31))]], 'primary.0.label'],
    'child label over 30' => [['primary' => [navGroup('G', [navItem('url', str_repeat('a', 31), ['url' => '/berita'])])]], 'primary.0.children.0.label'],
    'footer label over 60' => [['footer' => [navItem('url', str_repeat('a', 61), ['url' => '/berita'])]], 'footer.0.label'],
    'button label over 30' => [['header_button' => navItem('psb.register', str_repeat('a', 31))], 'header_button.label'],
    'nested group' => [['primary' => [navGroup('G', [navGroup('Dalam', [navItem('home')])])]], 'primary.0.children.0.type'],
    'group without children' => [['primary' => [navGroup('G', [])]], 'primary.0.children'],
    'group in the footer' => [['footer' => [navGroup('G', [navItem('home')])]], 'footer.0.type'],
    'group as the button' => [['header_button' => navGroup('G', [navItem('home')])], 'header_button.type'],
    'invalid child page' => [['primary' => [navGroup('G', [navItem('home'), navItem('page', null, ['page_id' => 999999])])]], 'primary.0.children.1.page_id'],
    'invalid footer url' => [['footer' => [navItem('home'), navItem('url', 'X', ['url' => 'javascript:x'])]], 'footer.1.url'],
    'invalid button page' => [['header_button' => navItem('page', null, ['page_id' => 999999])], 'header_button.page_id'],
]);

it('AC-5: rejects lists over the limit, names the limit and never truncates', function (Closure $data, string $field, string $limit) {
    navAssertRejected($data(), $field, $limit);
})->with([
    'nine root items' => [fn () => ['primary' => array_fill(0, 9, navItem('home'))], 'primary', '8'],
    'nine children' => [fn () => ['primary' => [navGroup('G', array_fill(0, 9, navItem('home')))]], 'primary.0.children', '8'],
    'thirteen footer items' => [fn () => ['footer' => array_fill(0, 13, navItem('home'))], 'footer', '12'],
]);

it('AC-5: accepts exactly the limits (8 roots, 8 children, 12 footer items)', function () {
    $roots = array_fill(0, 7, navItem('home'));
    $roots[] = navGroup('G', array_fill(0, 8, navItem('home')));

    navSave(['primary' => $roots, 'footer' => array_fill(0, 12, navItem('home'))]);

    expect(Setting::get('portal.navigation_primary'))->toHaveCount(8)
        ->and(Setting::get('portal.navigation_primary')[7]['children'])->toHaveCount(8)
        ->and(Setting::get('portal.navigation_footer'))->toHaveCount(12);
});

it('AC-5: one invalid part blocks the whole save, including the valid parts', function () {
    navSave(['primary' => [navItem('home')], 'footer' => [navItem('psb.status')]]);
    $before = profilSettingsSnapshot();

    expect(fn () => navSave(['primary' => [navItem('home'), navItem('posts')], 'footer' => [navItem('url', 'X', ['url' => 'javascript:x'])]]))->toThrow(ValidationException::class);

    expect(profilSettingsSnapshot())->toBe($before);
});

it('AC-5: refuses hand-made scalar input without storing junk', function (mixed $primary) {
    try {
        navSave(['primary' => $primary]);
    } catch (ValidationException) {
        // ditolak atau dianggap kosong: keduanya boleh
    }

    $stored = Setting::get('portal.navigation_primary');

    expect($stored === null || $stored === [])->toBeTrue();
})->with(['scalar' => ['bukan-daftar'], 'junk rows' => [['x', 5]]]);
