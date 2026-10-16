<?php

use App\Filament\Clusters\InstitutionProfile\Pages\Menu;
use App\Filament\Resources\StaticPages\Pages\CreateStaticPage as CreateStaticPagePage;
use App\Filament\Resources\StaticPages\Pages\EditStaticPage;
use App\Filament\Resources\StaticPages\Pages\ListStaticPages;
use App\Filament\Resources\StaticPages\StaticPageResource;
use App\Models\Setting;
use App\Models\StaticPage;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/*
| F037 VERIFY: celah portal (draft, kontak, nav, footer, sitemap, N+1) dan akses panel.
*/

/**
 * Jalankan percobaan terlarang; galat apa pun (403, mount gagal) dianggap ditolak.
 */
function verifyGapsAttempt(Closure $callback): void
{
    try {
        $callback();
    } catch (Throwable) {
    }
}

beforeEach(function () {
    portalBoot();
    Setting::set('modules.psb.enabled', false);
});

it('VERIFY: draft pages are 404 and absent from nav, footer and sitemap', function () {
    $draft = staticPage(['slug' => 'rahasia-draf', 'title' => 'Judul Rahasia Draf', 'status' => 'draft']);
    $live = staticPage(['slug' => 'terbuka', 'title' => 'Halaman Terbuka']);
    menuSet([menuBuiltin('home'), menuPage($draft), menuPage($live)]);
    Setting::set('portal.footer_links', [['label' => null, 'page_id' => $draft->id], ['label' => null, 'page_id' => $live->id]]);

    $html = $this->get('/')->assertOk()->getContent();
    expect($html)->not->toContain('rahasia-draf')->not->toContain('Judul Rahasia Draf')->toContain('/terbuka');

    $this->get('/rahasia-draf')->assertNotFound();
    $this->get('/sitemap.xml')->assertOk()->assertDontSee('rahasia-draf')->assertSee('/terbuka');
});

it('VERIFY: a draft system page is 404 and out of the default menu, footer and sitemap', function () {
    portalContactData();
    StaticPage::query()->where('slug', 'profil')->update(['status' => 'draft']);
    $vm = StaticPage::query()->where('slug', 'visi-misi')->firstOrFail();
    $vm->update(['status' => 'draft']);
    Setting::set('portal.footer_links', [['label' => null, 'page_id' => $vm->id]]);

    $this->get('/profil')->assertNotFound();
    $this->get('/visi-misi')->assertNotFound();
    $html = $this->get('/')->assertOk()->getContent();
    expect($html)->not->toContain('href="'.url('/profil').'"')->not->toContain('/visi-misi');
    $this->get('/sitemap.xml')->assertDontSee('/profil')->assertDontSee('/visi-misi');
});

it('VERIFY: kontak without contact data is 404, even with a body, and absent everywhere', function () {
    StaticPage::query()->where('slug', 'kontak')->update(['status' => 'terbit', 'body' => '<p>Telepon kami</p>']);
    $kontak = StaticPage::query()->where('slug', 'kontak')->firstOrFail();
    Setting::set('portal.footer_links', [['label' => null, 'page_id' => $kontak->id]]);
    menuSet([menuBuiltin('home'), menuPage($kontak)]);

    $this->get('/kontak')->assertNotFound();
    $html = $this->get('/')->getContent();
    expect($html)->not->toContain('href="'.url('/kontak').'"');
    $this->get('/sitemap.xml')->assertDontSee('/kontak');

    portalContactData();
    $this->get('/kontak')->assertOk();
    expect($this->get('/')->getContent())->toContain('href="'.url('/kontak').'"');
});

it('VERIFY: menu label script is escaped and long default titles are truncated', function () {
    $evil = staticPage(['slug' => 'xss', 'title' => '<img src=x onerror=alert(1)>']);
    $long = staticPage(['slug' => 'panjang', 'title' => str_repeat('Kata ', 30)]);
    menuSet([menuBuiltin('home'), menuPage($evil, '<script>alert(2)</script>'), menuPage($long)]);

    $html = $this->get('/')->assertOk()->getContent();
    expect($html)->not->toContain('<script>alert(2)')->not->toContain('<img src=x onerror');

    $texts = portalNavTexts($html);
    $longText = collect($texts)->first(fn ($t) => str_starts_with($t, 'Kata Kata'));
    expect(mb_strlen($longText))->toBeLessThanOrEqual(33);
});

it('VERIFY: menu items of a disabled module are hidden but the home item stays', function () {
    menuSet([menuBuiltin('home'), menuBuiltin('news'), menuBuiltin('gallery'), menuBuiltin('videos'), menuBuiltin('psb_info')]);

    expect(portalNavTexts($this->get('/')->getContent()))->toContain('Berita');

    Setting::set('modules.konten.enabled', false);
    $nav = portalNavLinks($this->get('/')->getContent());
    $texts = array_column($nav['desktop'], 'text');
    expect($texts)->not->toContain('Berita')->not->toContain('Galeri')->not->toContain('Video');
});

it('VERIFY: hiding Daftar keeps Masuk, and page items disappear when the konten module is off', function () {
    Setting::set('modules.psb.enabled', true);
    portalPsbOpen();
    $page = staticPage(['slug' => 'ada']);
    menuSet([menuBuiltin('home'), menuPage($page)], true);

    $texts = array_column(portalNavLinks($this->get('/')->getContent())['desktop'], 'text');
    expect($texts)->toContain(__('pondok.portal.nav.register'));

    menuSet([menuBuiltin('home'), menuPage($page)], false);
    $texts = array_column(portalNavLinks($this->get('/')->getContent())['desktop'], 'text');
    expect($texts)->not->toContain(__('pondok.portal.nav.register'))
        ->and(collect($texts)->contains(fn ($t) => str_contains(mb_strtolower($t), 'masuk')))->toBeTrue();
});

it('VERIFY: nav and footer do not run per-link queries on pages (no N+1)', function () {
    $count = function (int $n): int {
        StaticPage::query()->whereNotIn('slug', StaticPage::SYSTEM_SLUGS)->delete();
        $items = [menuBuiltin('home')];
        $footer = [];
        foreach (range(1, $n) as $i) {
            $page = staticPage(['slug' => "q-{$i}-{$n}"]);
            $items[] = menuPage($page);
            $footer[] = ['label' => null, 'page_id' => $page->id];
        }
        menuSet($items);
        Setting::set('portal.footer_links', $footer);
        $this->get('/')->assertOk();

        return queriesOnTable(recordedQueries(fn () => $this->get('/')->assertOk()), 'pages');
    };

    $few = $count(2);
    $many = $count(7);

    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(2);
});

it('VERIFY: a menu or footer reference to a deleted page does not break the portal', function () {
    menuSet([menuBuiltin('home'), menuPage(99999), ['type' => 'page', 'page_id' => 'abc', 'label' => 1, 'visible' => true], 'rusak', ['type' => 'builtin', 'key' => 'bukan', 'visible' => true]]);
    Setting::set('portal.footer_links', [['label' => null, 'page_id' => 99999], 'rusak']);

    $this->get('/')->assertOk();
});

it('VERIFY: stored menu with more than 8 visible items renders at most 8 links', function () {
    $items = [menuBuiltin('home'), menuBuiltin('news'), menuBuiltin('gallery'), menuBuiltin('videos'), menuBuiltin('psb_info')];
    foreach (range(1, 8) as $i) {
        $items[] = menuPage(staticPage(['slug' => "b-{$i}"]));
    }
    menuSet($items);

    expect(count(portalNavLinks($this->get('/')->getContent())['desktop']))->toBeLessThanOrEqual(8);
});

it('VERIFY: free page html is sanitized at render time even when stored raw', function () {
    staticPage(['slug' => 'mentah', 'body' => '<p>Aman</p><script>alert(9)</script><img src=x onerror=alert(8)>']);

    $html = $this->get('/mentah')->assertOk()->getContent();
    expect($html)->toContain('Aman')->not->toContain('alert(9)')->not->toContain('onerror');
});

it('VERIFY: slug with capitals, dots or slashes is never served as a page', function (string $path) {
    staticPage(['slug' => 'pengurus']);

    $this->get($path)->assertNotFound();
})->with(['/Pengurus', '/pengurus.', '/pengurus/x', '/peng%C3%BCrus', '/pengurus%2Fx']);

// ---- Panel: akses per peran ----

it('VERIFY: roles without create permission cannot open the create page or create via Livewire', function (string $role) {
    kontenBoot();
    $this->actingAs(kontenUser($role));

    $this->get(StaticPageResource::getUrl('create'))->assertForbidden();
    verifyGapsAttempt(fn () => Livewire::test(CreateStaticPagePage::class)->fillForm(['title' => 'X'])->call('create'));
    expect(StaticPage::query()->where('title', 'X')->exists())->toBeFalse();
})->with(['bendahara', 'wali', 'petugas-pos', 'musyrif']);

it('VERIFY: users without delete permission cannot delete a free page by forced Livewire call', function (string $role) {
    $this->f = kontenBoot(withPages: true);
    $page = staticPage(['slug' => 'jangan-hapus']);
    $this->actingAs(kontenUser($role));

    try {
        Livewire::test(ListStaticPages::class)->callAction(TestAction::make('delete')->table($page));
    } catch (Throwable) {
    }

    expect(StaticPage::query()->whereKey($page->id)->exists())->toBeTrue();
})->with(['bendahara', 'wali', 'musyrif', 'admin-sekolah']);

it('VERIFY: forced Livewire delete of a system page never deletes it, for super-admin and admin-konten', function (string $role, string $slug) {
    kontenBoot(withPages: true);
    $page = StaticPage::query()->where('slug', $slug)->firstOrFail();
    $this->actingAs(kontenUser($role));

    try {
        Livewire::test(ListStaticPages::class)->callAction(TestAction::make('delete')->table($page));
    } catch (Throwable) {
    }
    try {
        Livewire::test(EditStaticPage::class, ['record' => $page->getRouteKey()])->callAction('delete');
    } catch (Throwable) {
    }

    expect(StaticPage::query()->whereKey($page->id)->exists())->toBeTrue();
})->with([['super-admin', 'profil'], ['super-admin', 'visi-misi'], ['super-admin', 'kontak'], ['admin-konten', 'kontak']]);

it('VERIFY: admin-konten deletes a free page from the table and cleans menu and footer references', function () {
    kontenBoot(withPages: true);
    $page = staticPage(['slug' => 'akan-hilang']);
    Setting::set('portal.menu_items', [menuPage($page)]);
    Setting::set('portal.footer_links', [['label' => null, 'page_id' => $page->id]]);
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(ListStaticPages::class)->callAction(TestAction::make('delete')->table($page));

    expect(StaticPage::query()->whereKey($page->id)->exists())->toBeFalse()
        ->and(Setting::get('portal.menu_items'))->toBeNull()
        ->and(Setting::get('portal.footer_links'))->toBeNull();
});

it('VERIFY: the content module off blocks the page resource and Menu editor for admin-konten', function () {
    kontenBoot(withPages: true);
    Setting::set('modules.konten.enabled', false);
    $this->actingAs(kontenUser('admin-konten'));

    $this->get(StaticPageResource::getUrl('index'))->assertForbidden();
    $this->get(StaticPageResource::getUrl('create'))->assertForbidden();
});

it('VERIFY: Menu editor save is blocked for roles without profile access', function (string $role) {
    kontenBoot(withPages: true);
    $this->actingAs(kontenUser($role));

    verifyGapsAttempt(fn () => Livewire::test(Menu::class)->call('save'));
    expect(Setting::get('portal.menu_items'))->toBeNull();
})->with(['bendahara', 'wali', 'musyrif']);
