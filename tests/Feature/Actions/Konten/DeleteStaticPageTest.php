<?php

use App\Actions\Konten\DeleteStaticPage;
use App\Models\Setting;
use App\Models\StaticPage;
use Illuminate\Support\Facades\Schema;

/*
| F037 AC-12 dan AC-13; F043 AC-10 (rujukan `portal.navigation_primary` akar dan anak grup, `portal.navigation_footer`, `portal.header_button` → false). Kontrak: `DeleteStaticPage::handle(StaticPage $page): void`.
*/

beforeEach(function () {
    $this->f = kontenBoot(withPages: true);
});

it('AC-10: hard deletes a page and detaches its references from primary menu, footer and header button', function () {
    $x = staticPage(['slug' => 'x-halaman']);
    $y = staticPage(['slug' => 'y-halaman']);
    navSet(
        [navItem('home'), navPage($x), navPage($y)],
        [navItem('url', 'Berita', ['url' => '/berita']), navPage($x), navPage($y, 'Y')],
        navPage($x),
    );

    app(DeleteStaticPage::class)->handle($x);

    expect(StaticPage::query()->whereKey($x->id)->exists())->toBeFalse()
        ->and(Schema::hasColumn('pages', 'deleted_at'))->toBeFalse()
        ->and(Setting::get('portal.navigation_primary'))->toEqual([navItem('home'), navPage($y)])
        ->and(Setting::get('portal.navigation_footer'))->toEqual([navItem('url', 'Berita', ['url' => '/berita']), navPage($y, 'Y')])
        ->and(Setting::get('portal.header_button'))->toBeFalse();
});

it('AC-10: removes the page from a group and drops a group that becomes empty', function () {
    $x = staticPage(['slug' => 'x-halaman']);
    $y = staticPage(['slug' => 'y-halaman']);
    $z = staticPage(['slug' => 'z-halaman']);
    navSet([navGroup('Dua', [navPage($x), navPage($y)]), navGroup('Satu', [navPage($x)]), navPage($z)]);

    app(DeleteStaticPage::class)->handle($x);

    expect(Setting::get('portal.navigation_primary'))->toEqual([navGroup('Dua', [navPage($y)]), navPage($z)]);
});

it('AC-10: an emptied menu stays an empty list instead of falling back to the default menu', function () {
    $x = staticPage();
    navSet([navPage($x)], [navPage($x)]);

    app(DeleteStaticPage::class)->handle($x);

    expect(Setting::get('portal.navigation_primary'))->toBe([])->and(Setting::get('portal.navigation_footer'))->toBe([]);
});

it('AC-10: leaves unset keys and a button pointing elsewhere untouched', function () {
    $x = staticPage();
    $y = staticPage();
    navSet(null, null, navPage($y));
    $before = profilSettingsSnapshot();

    app(DeleteStaticPage::class)->handle($x);

    expect(profilSettingsSnapshot())->toBe($before);
});

it('AC-12: deletes a page that nothing references', function () {
    $x = staticPage();

    app(DeleteStaticPage::class)->handle($x);

    expect(StaticPage::query()->whereKey($x->id)->exists())->toBeFalse();
});

it('AC-13: refuses to delete system pages, even for a super admin actor', function (string $slug) {
    $this->actingAs(kontenUser('super-admin'));
    $page = StaticPage::findBySlug($slug);

    kontenAssertRejected(fn () => app(DeleteStaticPage::class)->handle($page), 'page_system_locked');

    expect(StaticPage::findBySlug($slug))->not->toBeNull();
})->with(['profil', 'visi-misi', 'kontak']);
