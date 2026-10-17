<?php

use App\Models\Setting;
use App\Models\StaticPage;

/*
| F037 AC-18 sampai AC-25 ditulis ulang untuk F043 (AC-3, AC-6, AC-7, AC-8). Kontrak markup header:
| - dua `<nav>` yang sama seperti sekarang (aria-label `pondok.portal.nav.label` dan `.mobile_label`);
| - tautan aktif ber-`aria-current="page"`; grup = `<details>` berisi `<summary>` (judul) dan tautan anak (desktop);
|   di seluler judul grup ditulis sebagai teks/summary diikuti tautan anak (tanpa `<details>` wajib);
| - grup yang salah satu anaknya aktif: `<summary ... aria-current="true">` (hanya di desktop);
| - tombol header = tautan terakhir di nav (setelah "Masuk"), bukan bagian daftar menu.
| Urutan teks tautan (anak grup ikut dalam urutan dokumen): item menu, Masuk, tombol.
*/

beforeEach(function () {
    portalBoot();
    Setting::set('modules.psb.enabled', false);
});

it('AC-3: the default menu matches the previous header when the keys are missing', function () {
    portalPsbOpen();
    portalContactData();
    navSeedPublicContent();

    expect(portalNavTexts($this->get('/')->assertOk()->getContent()))
        ->toBe(['Beranda', 'Profil', 'Berita', 'Galeri', 'Video', 'Kontak', 'Masuk', 'Daftar']);
});

it('AC-3: an empty menu leaves only Masuk, and the register button follows header_button', function (?array $primary, array|false|null $button, array $expected) {
    portalPsbOpen();
    navSet($primary, $button);

    expect(portalNavTexts($this->get('/')->assertOk()->getContent()))->toBe($expected);
})->with([
    'empty menu' => [[], null, ['Masuk', 'Daftar']],
    'no button' => [[], false, ['Masuk']],
    'custom button label' => [[], navItem('psb.register', 'Ikut Daftar'), ['Masuk', 'Ikut Daftar']],
]);

it('AC-7: renders the stored order and labels identically in both navs', function () {
    portalContactData();
    $pengurus = staticPage(['slug' => 'pengurus', 'title' => 'Pengurus Al-Khair']);
    navSeedPublicContent();
    navSet([navPage($pengurus, 'Pengurus'), navItem('home'), navItem('albums', 'Foto'), navPage(StaticPage::findBySlug('kontak')), navItem('url', 'Situs Luar', ['url' => 'https://contoh.test/x'])]);

    $links = portalNavLinks($this->get('/')->assertOk()->getContent());

    expect(portalNavTexts($this->get('/')->getContent()))->toBe(['Pengurus', 'Beranda', 'Foto', 'Kontak', 'Situs Luar', 'Masuk'])
        ->and(array_column($links['desktop'], 'href'))->toBe([url('/pengurus'), url('/'), route('portal.albums.index'), route('portal.pages.show', 'kontak'), 'https://contoh.test/x', route('filament.admin.auth.login')]);
});

it('AC-7: renders a group as details with its child links, and as a heading with indented children on mobile', function () {
    $a = staticPage(['slug' => 'pengurus', 'title' => 'Pengurus']);
    $b = staticPage(['slug' => 'selayang-pandang', 'title' => 'Selayang Pandang']);
    navSet([navItem('home'), navGroup('Profil Lembaga', [navPage($a), navPage($b)]), navItem('url', 'Luar', ['url' => 'https://contoh.test'])]);

    $html = $this->get('/')->assertOk()->getContent();
    $desktop = navHtml($html, 'desktop');
    $mobile = navHtml($html, 'mobile');

    expect(substr_count($desktop, '<details'))->toBe(1)
        ->and($desktop)->toMatch('#<details\b[^>]*>\s*<summary\b[^>]*>.*?Profil Lembaga.*?</summary>.*?href="'.preg_quote(url('/pengurus'), '#').'".*?href="'.preg_quote(url('/selayang-pandang'), '#').'".*?</details>#s')
        ->and(portalNavTexts($html))->toBe(['Beranda', 'Pengurus', 'Selayang Pandang', 'Luar', 'Masuk'])
        ->and($mobile)->toContain('Profil Lembaga')
        ->and(strpos($mobile, 'Profil Lembaga'))->toBeLessThan(strpos($mobile, 'Pengurus'))
        ->and(strpos($mobile, 'Pengurus'))->toBeLessThan(strpos($mobile, 'Selayang Pandang'))
        ->and(strpos($mobile, 'Selayang Pandang'))->toBeLessThan(strpos($mobile, 'Luar'));
});

it('AC-7: omits a group whose children are all unavailable and keeps the other items', function () {
    navSet([navItem('home'), navGroup('Kosong', [navPage(staticPage(['status' => 'draft']))])]);

    $html = $this->get('/')->assertOk()->getContent();

    expect(navHtml($html, 'desktop'))->not->toContain('<details')->not->toContain('Kosong')
        ->and(portalNavTexts($html))->toBe(['Beranda', 'Masuk']);
});

it('AC-7: marks the active link with aria-current in both navs, per destination type', function (string $slug, Closure $items, string $path, array $expected) {
    portalPsbOpen();
    $pengurus = staticPage(['slug' => 'pengurus', 'title' => 'Pengurus']);
    $category = kontenCategory('post', 'Pengumuman', 'pengumuman');
    kontenCategory('post', 'Lainnya', 'lainnya');
    portalPost(['title' => 'Wisuda 2026', 'category_id' => $category->id]);
    portalAlbumWithPhotos(1, ['title' => 'Album Uji']);
    kontenVideo();
    StaticPage::query()->where('slug', 'visi-misi')->update(['status' => 'terbit']);
    navSet($items($pengurus, $category), false);

    expect(navCurrent($this->get($path)->assertOk()->getContent()))->toBe($expected);
})->with([
    'page' => ['page', fn ($p) => [navItem('home'), navPage($p), navItem('posts')], '/pengurus', ['Pengurus']],
    'vision page does not mark the profile item' => ['vision', fn ($p) => [navPage(StaticPage::findBySlug('profil')), navItem('posts')], '/visi-misi', []],
    'posts index' => ['posts', fn ($p) => [navItem('home'), navItem('posts')], '/berita', ['Berita']],
    'post detail' => ['post', fn ($p) => [navItem('home'), navItem('posts')], '/berita/wisuda-2026', ['Berita']],
    'category with the same query' => ['category', fn ($p, $c) => [navItem('category', null, ['category_id' => $c->id])], '/berita?kategori=pengumuman', ['Pengumuman']],
    'category with another query' => ['category', fn ($p, $c) => [navItem('category', null, ['category_id' => $c->id])], '/berita?kategori=lainnya', []],
    'category without a query' => ['category', fn ($p, $c) => [navItem('category', null, ['category_id' => $c->id])], '/berita', []],
    'albums' => ['albums', fn ($p) => [navItem('home'), navItem('albums')], '/galeri', ['Galeri']],
    'videos' => ['videos', fn ($p) => [navItem('home'), navItem('videos')], '/video', ['Video']],
    'psb register' => ['psb', fn ($p) => [navItem('home'), navItem('psb.register', 'Formulir')], '/psb', ['Formulir']],
    'psb status' => ['psb', fn ($p) => [navItem('home'), navItem('psb.status')], '/psb/status', ['Cek status pendaftaran']],
    'home' => ['home', fn ($p) => [navItem('home'), navItem('posts')], '/', ['Beranda']],
    'url is never active' => ['url', fn ($p) => [navItem('url', 'Berita Luar', ['url' => '/berita'])], '/berita', []],
]);

it('AC-7: marks the parent group active when a child is active, and only then', function () {
    $a = staticPage(['slug' => 'pengurus', 'title' => 'Pengurus']);
    $b = staticPage(['slug' => 'selayang-pandang', 'title' => 'Selayang Pandang']);
    navSet([navItem('home'), navGroup('Profil Lembaga', [navPage($a), navPage($b)])]);

    $onChild = navHtml($this->get('/pengurus')->assertOk()->getContent(), 'desktop');
    $elsewhere = navHtml($this->get('/')->assertOk()->getContent(), 'desktop');

    expect($onChild)->toMatch('#<summary\b[^>]*aria-current="true"#')
        ->and($elsewhere)->not->toMatch('#<summary\b[^>]*aria-current#');
});

it('AC-7: renders the header button with the saved label and target, not as a menu item', function () {
    $page = staticPage(['slug' => 'hubungi', 'title' => 'Hubungi Kami']);
    navSet([navItem('home')], navPage($page, 'Tanya Panitia'));

    $links = portalNavLinks($this->get('/')->assertOk()->getContent());

    expect(end($links['desktop']))->toMatchArray(['text' => 'Tanya Panitia', 'href' => url('/hubungi')])
        ->and(end($links['mobile']))->toMatchArray(['text' => 'Tanya Panitia', 'href' => url('/hubungi')])
        ->and(array_column($links['desktop'], 'text'))->toBe(['Beranda', 'Masuk', 'Tanya Panitia']);
});

it('AC-7: does not render a header button whose target is unavailable', function () {
    portalPsbOpen();
    $page = staticPage(['slug' => 'hubungi', 'status' => 'draft']);

    navSet([navItem('home')], navPage($page, 'Tanya'));
    expect(portalNavTexts($this->get('/')->getContent()))->toBe(['Beranda', 'Masuk']);

    navSet([navItem('home')], navItem('psb.register'));
    Setting::set('modules.psb.enabled', false);
    expect(portalNavTexts($this->get('/')->getContent()))->toBe(['Beranda', 'Masuk']);
});

it('AC-7: escapes labels and urls in the header', function () {
    $x = '<script>alert(1)</script>';
    navSet([navItem('home'), navGroup($x, [navItem('url', $x, ['url' => 'https://contoh.test/?a=1&b="x'])]), navItem('url', $x, ['url' => '/berita'])], navItem('url', $x, ['url' => '/berita']));

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)')->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and(navHtml($html, 'desktop'))->not->toContain('b="x');
});

it('AC-6: hides items whose page is a draft or gone without breaking the portal', function () {
    $draft = staticPage(['slug' => 'draf', 'title' => 'Halaman Draf', 'status' => 'draft']);
    $shown = staticPage(['slug' => 'tampil', 'title' => 'Halaman Tampil']);
    navSet([navItem('home'), navPage($draft), navPage(999999), ['type' => 'page', 'page_id' => 'abc'], 'rusak', navPage($shown)]);

    $texts = portalNavTexts($this->get('/')->assertOk()->getContent());

    expect($texts)->toBe(['Beranda', 'Halaman Tampil', 'Masuk']);
});

it('AC-6: shows only Beranda and Masuk, with no content queries, when the content module is off', function () {
    staticPage(['slug' => 'pengurus', 'title' => 'Pengurus']);
    navSet([navItem('home'), navPage(StaticPage::findBySlug('pengurus')), navItem('posts'), navItem('albums'), navItem('videos'), navItem('category', null, ['category_id' => 1])]);
    Setting::set('modules.konten.enabled', false);
    $html = '';

    $queries = recordedQueries(function () use (&$html): void {
        $html = $this->get('/')->assertOk()->getContent();
    });

    expect(portalNavTexts($html))->toBe(['Beranda', 'Masuk'])
        ->and(queriesOnTable($queries, 'pages'))->toBe(0)
        ->and(queriesOnTable($queries, 'posts'))->toBe(0)
        ->and(queriesOnTable($queries, 'albums'))->toBe(0)
        ->and(queriesOnTable($queries, 'videos'))->toBe(0)
        ->and(queriesOnTable($queries, 'categories'))->toBe(0);
});

it('AC-8: resolves menu and footer widget pages with at most two pages queries and no repeated settings query', function () {
    $pages = collect(range(1, 4))->map(fn (int $i) => staticPage(['slug' => "menu-{$i}", 'title' => "Menu {$i}"]));
    $footer = collect(range(1, 2))->map(fn (int $i) => staticPage(['slug' => "foot-{$i}", 'title' => "Foot {$i}"]));
    navSet([navItem('home'), ...$pages->map(fn ($p): array => navPage($p))->all(), navGroup('Grup', [navPage($footer[0])])], navPage($footer[1]));
    ftrSet([ftr('links', ['items' => $footer->map(fn ($p): array => navPage($p))->all()])]);

    $queries = recordedQueries(fn () => $this->get('/berita')->assertOk());
    $settings = array_map(fn (array $q): string => $q['sql'].json_encode($q['bindings']), array_filter($queries, fn (array $q): bool => str_contains($q['sql'], 'settings')));

    expect(queriesOnTable($queries, 'pages'))->toBeLessThanOrEqual(2)
        ->and(count($settings))->toBe(count(array_unique($settings)));
});

it('AC-8: the number of page queries does not grow with the number of linked pages', function () {
    $count = function (int $n): int {
        StaticPage::query()->whereNotIn('slug', ['profil', 'visi-misi', 'kontak'])->delete();
        $pages = collect(range(1, $n))->map(fn (int $i) => staticPage(['slug' => "q-{$i}-{$n}"]));
        navSet([navItem('home'), ...$pages->map(fn ($p): array => navPage($p))->take(4)->all(), navGroup('G', $pages->skip(4)->take(4)->map(fn ($p): array => navPage($p))->values()->all() ?: [navItem('home')])]);
        ftrSet([ftr('links', ['items' => array_slice($pages->map(fn ($p): array => navPage($p))->all(), 0, 8)])]);
        $this->get('/berita')->assertOk();

        return queriesOnTable(recordedQueries(fn () => $this->get('/berita')->assertOk()), 'pages');
    };

    expect($count(8))->toBe($count(2))->and($count(8))->toBeLessThanOrEqual(2);
});

it('AC-3: ignores the removed legacy menu keys', function () {
    Setting::set('portal.menu_items', [menuBuiltin('news')]);
    Setting::set('portal.menu_show_register', false);
    portalContactData();
    navSeedPublicContent();

    expect(portalNavTexts($this->get('/')->assertOk()->getContent()))->toBe(['Beranda', 'Profil', 'Berita', 'Galeri', 'Video', 'Kontak', 'Masuk']);
});
