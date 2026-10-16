<?php

use App\Actions\Konten\DeleteStaticPage;
use App\Models\Setting;
use App\Models\StaticPage;
use Illuminate\Support\Facades\Schema;

/*
| F037 AC-12 dan AC-13. Kontrak: `DeleteStaticPage::handle(StaticPage $page): void`.
*/

beforeEach(function () {
    $this->f = kontenBoot(withPages: true);
});

it('AC-12: hard deletes a page and detaches menu and footer references', function () {
    $x = staticPage(['slug' => 'x-halaman']);
    $y = staticPage(['slug' => 'y-halaman']);
    Setting::set('portal.menu_items', [menuBuiltin('home'), menuPage($x), menuPage($y)]);
    Setting::set('portal.footer_links', [['label' => 'Berita', 'url' => '/berita'], ['label' => null, 'page_id' => $x->id], ['label' => 'Y', 'page_id' => $y->id]]);

    app(DeleteStaticPage::class)->handle($x);

    expect(StaticPage::query()->whereKey($x->id)->exists())->toBeFalse()
        ->and(Schema::hasColumn('pages', 'deleted_at'))->toBeFalse()
        ->and(Setting::get('portal.menu_items'))->toBe([menuBuiltin('home'), menuPage($y)])
        ->and(Setting::get('portal.footer_links'))->toBe([['label' => 'Berita', 'url' => '/berita'], ['label' => 'Y', 'page_id' => $y->id]]);
});

it('AC-12: stores null when the deleted page was the only reference', function () {
    $x = staticPage();
    Setting::set('portal.menu_items', [menuPage($x)]);
    Setting::set('portal.footer_links', [['label' => null, 'page_id' => $x->id]]);

    app(DeleteStaticPage::class)->handle($x);

    expect(Setting::get('portal.menu_items'))->toBeNull()->and(Setting::get('portal.footer_links'))->toBeNull();
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
