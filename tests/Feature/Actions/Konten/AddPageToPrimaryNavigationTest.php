<?php

use App\Actions\Konten\AddPageToPrimaryNavigation;
use App\Models\Setting;
use App\Models\StaticPage;
use Illuminate\Validation\ValidationException;

/*
| F043 AC-9. Kontrak: `AddPageToPrimaryNavigation::handle(StaticPage $page, User $actor): string` mengembalikan
| `'added'` atau `'already_listed'` (konstanta `ADDED` dan `ALREADY_LISTED` bernilai sama). Menu penuh (8 akar) melempar
| `ValidationException` pada field `primary` tanpa menulis apa pun. Menu null dimaterialisasi dari bawaan lebih dulu
| (home, page:profil, posts, albums, videos, psb.info, page:kontak; hanya halaman yang ada), `label` item baru `null`.
*/

beforeEach(function () {
    $this->f = kontenBoot(withPages: true);
    $this->admin = kontenUser('admin-konten');
});

it('AC-9: appends the page at the end of the stored menu', function () {
    $a = staticPage(['slug' => 'pengurus']);
    $b = staticPage(['slug' => 'selayang-pandang']);
    navSet([navItem('home'), navPage($a, 'Pengurus')]);

    $result = app(AddPageToPrimaryNavigation::class)->handle($b, $this->admin);

    expect($result)->toBe('added')
        ->and(Setting::get('portal.navigation_primary'))->toEqual([navItem('home'), navPage($a, 'Pengurus'), navPage($b)]);
});

it('AC-9: materializes the default menu first so the other items stay (only existing pages)', function () {
    $page = staticPage(['slug' => 'pengurus']);
    StaticPage::query()->where('slug', 'kontak')->delete();
    $profil = StaticPage::findBySlug('profil');

    app(AddPageToPrimaryNavigation::class)->handle($page, $this->admin);

    expect(Setting::get('portal.navigation_primary'))->toEqual([
        navItem('home'), navPage($profil), navItem('posts'), navItem('albums'), navItem('videos'), navItem('psb.info'), navPage($page),
    ]);
});

it('AC-9: does not duplicate a page already in the menu (root or group child) and writes nothing', function (string $placement) {
    $page = staticPage(['slug' => 'pengurus']);
    navSet($placement === 'root' ? [navItem('home'), navPage($page)] : [navItem('home'), navGroup('Profil', [navPage($page)])]);
    $before = profilSettingsSnapshot();

    $result = app(AddPageToPrimaryNavigation::class)->handle($page, $this->admin);

    expect($result)->toBe('already_listed')->and(profilSettingsSnapshot())->toBe($before);
})->with(['root' => ['root'], 'group child' => ['child']]);

it('AC-9: refuses when the menu already has eight root items and writes nothing', function () {
    $page = staticPage(['slug' => 'pengurus']);
    navSet(array_fill(0, 8, navItem('home')));
    $before = profilSettingsSnapshot();

    expect(fn () => app(AddPageToPrimaryNavigation::class)->handle($page, $this->admin))->toThrow(ValidationException::class);

    expect(profilSettingsSnapshot())->toBe($before);
});

it('AC-9: a full default menu is refused instead of silently dropping an item', function () {
    // bawaan penuh: home, profil, posts, albums, videos, psb.info, kontak = 7 akar, jadi masih muat satu; yang kedua ditolak.
    $a = staticPage(['slug' => 'a-halaman']);
    $b = staticPage(['slug' => 'b-halaman']);

    expect(app(AddPageToPrimaryNavigation::class)->handle($a, $this->admin))->toBe('added')
        ->and(fn () => app(AddPageToPrimaryNavigation::class)->handle($b, $this->admin))->toThrow(ValidationException::class)
        ->and(Setting::get('portal.navigation_primary'))->toHaveCount(8)
        ->and(array_column(Setting::get('portal.navigation_primary'), 'page_id'))->not->toContain($b->id);
});

it('AC-9: adds a draft page too (it only appears once published)', function () {
    $draft = staticPage(['slug' => 'draf', 'status' => 'draft']);
    navSet([navItem('home')]);

    expect(app(AddPageToPrimaryNavigation::class)->handle($draft, $this->admin))->toBe('added')
        ->and(Setting::get('portal.navigation_primary'))->toEqual([navItem('home'), navPage($draft)]);
});
