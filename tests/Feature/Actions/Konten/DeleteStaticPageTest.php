<?php

use App\Actions\Konten\DeleteStaticPage;
use App\Models\Setting;
use App\Models\StaticPage;
use Illuminate\Support\Facades\Schema;

/*
| F037 AC-12 dan AC-13; F043 AC-10 (rujukan `portal.navigation_primary` akar dan anak grup, `portal.header_button` → false); F046 AC-8: `portal.navigation_footer` dan `portal.footer_widgets` tidak disentuh. Kontrak: `DeleteStaticPage::handle(StaticPage $page): void`.
*/

beforeEach(function () {
    $this->f = kontenBoot(withPages: true);
});

it('AC-10: hard deletes a page and detaches its references from the primary menu and header button, and leaves footer widgets alone', function () {
    $x = staticPage(['slug' => 'x-halaman']);
    $y = staticPage(['slug' => 'y-halaman']);
    navSet([navItem('home'), navPage($x), navPage($y)], navPage($x));
    $widgets = [ftr('links', ['heading' => 'Tautan', 'items' => [navPage($x), navPage($y, 'Y')]])];
    ftrSet($widgets);
    profilRawSet('portal.navigation_footer', [navPage($x)]);

    app(DeleteStaticPage::class)->handle($x);

    expect(StaticPage::query()->whereKey($x->id)->exists())->toBeFalse()
        ->and(Schema::hasColumn('pages', 'deleted_at'))->toBeFalse()
        ->and(Setting::get('portal.navigation_primary'))->toEqual([navItem('home'), navPage($y)])
        ->and(ftrStored())->toEqual($widgets)
        ->and(profilRawGet('portal.navigation_footer'))->toEqual([navPage($x)])
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
    navSet([navPage($x)]);

    app(DeleteStaticPage::class)->handle($x);

    expect(Setting::get('portal.navigation_primary'))->toBe([]);
});

it('AC-10: leaves unset keys and a button pointing elsewhere untouched', function () {
    $x = staticPage();
    $y = staticPage();
    navSet(null, navPage($y));
    $before = profilSettingsSnapshot();

    app(DeleteStaticPage::class)->handle($x);

    expect(profilSettingsSnapshot())->toBe($before);
});

it('AC-12: deletes a page that nothing references', function () {
    $x = staticPage();

    app(DeleteStaticPage::class)->handle($x);

    expect(StaticPage::query()->whereKey($x->id)->exists())->toBeFalse();
});

it('AC-6 (F048): deletes profil, visi-misi and kontak, even for a super admin, and still detaches menu and header button references', function (string $slug) {
    $this->actingAs(kontenUser('super-admin'));
    $page = StaticPage::findBySlug($slug);
    navSet([navItem('home'), navPage($page)], navPage($page));

    app(DeleteStaticPage::class)->handle($page);

    expect(StaticPage::findBySlug($slug))->toBeNull()
        ->and(Setting::get('portal.navigation_primary'))->toEqual([navItem('home')])
        ->and(Setting::get('portal.header_button'))->toBeFalse();
})->with(['profil', 'visi-misi', 'kontak']);
