<?php

use App\Actions\Konten\GetPortalNavigation;
use App\Actions\Konten\GetSchoolProfile;
use App\Models\Setting;
use App\Models\StaticPage;
use Illuminate\Support\Str;

/*
| F043 AC-3, AC-6, AC-8 (+ Minor 1 dan 2). Kontrak:
| `GetPortalNavigation::handle(array $school): array{primary: list<Link|Group>, headerButton: ?Link}` (tanpa `footer`: footer = widget, F046)
| dengan `Link = {label, url, active}` dan `Group = {label, children: list<Link>, active}` (grup dikenali dari key `children`).
| Bawaan (key settings tidak ada): primary = home, page:profil, posts, albums, videos, psb.info, page:kontak (halaman dicari
| per slug saat baca, hanya yang ada); tombol = psb.register.
*/

beforeEach(function () {
    portalBoot();
    Setting::set('modules.psb.enabled', true);
    Setting::set('modules.dokumen.enabled', true);
});

it('AC-3: uses the F037 default menu and register button when the keys are missing', function () {
    navSeedPublicContent();
    portalContactData();
    portalPsbOpen();
    $nav = navResolve();

    expect(navLabels($nav['primary']))->toBe(['Beranda', 'Profil', 'Berita', 'Galeri', 'Video', 'Info PSB', 'Kontak'])
        ->and(array_column($nav['primary'], 'url'))->toBe([route('portal.home'), route('portal.pages.show', 'profil'), route('portal.posts.index'), route('portal.albums.index'), route('portal.videos.index'), route('psb.info'), route('portal.pages.show', 'kontak')])
        ->and($nav['headerButton'])->toMatchArray(['label' => 'Daftar', 'url' => route('psb.register')])
        ->and($nav)->not->toHaveKey('footer');
});

it('AC-3: the default menu only contains existing published pages and skips contact without data', function () {
    navSeedPublicContent();
    StaticPage::query()->where('slug', 'profil')->update(['status' => 'draft']);

    expect(navLabels(navResolve()['primary']))->toBe(['Beranda', 'Berita', 'Galeri', 'Video']);

    portalContactData();
    StaticPage::query()->where('slug', 'kontak')->delete();

    expect(navLabels(navResolve()['primary']))->toBe(['Beranda', 'Berita', 'Galeri', 'Video']);
});

it('AC-8 (F046): the navigation has no footer, even when an old footer key is still stored', function () {
    profilRawSet('portal.navigation_footer', [navItem('url', 'Sisa Lama', ['url' => '/berita'])]);

    expect(navResolve())->not->toHaveKey('footer')->and(GetPortalNavigation::SETTING_KEYS)->not->toContain('portal.navigation_footer');
});

it('AC-3: an empty primary list renders no menu items and a false button renders no button', function () {
    portalPsbOpen();
    navSet([], false);
    $nav = navResolve();

    expect($nav['primary'])->toBe([])->and($nav['headerButton'])->toBeNull();
});

it('AC-3: the register button hides when psb is off even if configured', function () {
    portalPsbOpen();
    navSet(null, navItem('psb.register'));
    expect(navResolve()['headerButton']['label'])->toBe('Daftar');

    Setting::set('modules.psb.enabled', false);
    expect(navResolve()['headerButton'])->toBeNull();
});

it('AC-6: renders the target of every available type with its url', function () {
    navSeedPublicContent();
    portalPsbOpen();
    $page = staticPage(['slug' => 'pengurus', 'title' => 'Pengurus']);
    $category = kontenCategory('post', 'Pengumuman');
    portalPost(['title' => 'Kabar', 'category_id' => $category->id]);
    navSet([
        navItem('home'), navPage($page), navItem('posts'), navItem('category', null, ['category_id' => $category->id]), navItem('albums'), navItem('videos'),
        navItem('psb.info'), navItem('psb.register'), navItem('psb.status'), navItem('letters.verify'),
        navItem('url', 'Situs Luar', ['url' => 'https://contoh.test/x']), navItem('url', 'Dalam', ['url' => '/berita']),
    ]);
    $nav = navResolve();

    expect(navLabels($nav['primary']))->toBe(['Beranda', 'Pengurus', 'Berita', 'Pengumuman', 'Galeri', 'Video', 'Info PSB', 'Daftar', 'Cek status pendaftaran', 'Verifikasi keaslian surat', 'Situs Luar', 'Dalam'])
        ->and(array_column($nav['primary'], 'url'))->toBe([
            route('portal.home'), route('portal.pages.show', 'pengurus'), route('portal.posts.index'), route('portal.posts.index', ['kategori' => 'pengumuman']),
            route('portal.albums.index'), route('portal.videos.index'), route('psb.info'), route('psb.register'), route('psb.status'), route('dokumen.letters.verify-form'),
            'https://contoh.test/x', '/berita',
        ])
        ->and($nav['primary'][3]['url'])->toEndWith('/berita?kategori=pengumuman');
});

it('AC-6: hides an unavailable target', function (Closure $arrange) {
    $item = $arrange();
    navSet([navItem('home'), $item]);

    expect(navLabels(navResolve()['primary']))->toBe(['Beranda']);
})->with([
    'posts without a published post' => [fn () => navItem('posts')],
    'albums without an album' => [fn () => navItem('albums')],
    'videos without a video' => [fn () => navItem('videos')],
    'posts while konten is off' => [function () {
        navSeedPublicContent();
        Setting::set('modules.konten.enabled', false);

        return navItem('posts');
    }],
    'albums while konten is off' => [function () {
        navSeedPublicContent();
        Setting::set('modules.konten.enabled', false);

        return navItem('albums');
    }],
    'videos while konten is off' => [function () {
        navSeedPublicContent();
        Setting::set('modules.konten.enabled', false);

        return navItem('videos');
    }],
    'draft page' => [fn () => navPage(staticPage(['status' => 'draft']))],
    'deleted page' => [fn () => navPage(999999)],
    'page while konten is off' => [function () {
        Setting::set('modules.konten.enabled', false);

        return navPage(staticPage());
    }],
    'contact page without contact data' => [fn () => navPage(StaticPage::findBySlug('kontak'))],
    'missing category' => [fn () => navItem('category', null, ['category_id' => 999999])],
    'category without a published post' => [function () {
        $category = kontenCategory('post', 'Kosong');
        portalPost(['category_id' => $category->id, 'status' => 'draft', 'published_at' => null]);

        return navItem('category', null, ['category_id' => $category->id]);
    }],
    'category of another type' => [function () {
        $category = kontenCategory('album', 'Album Saja');
        navSeedPublicContent();

        return navItem('category', null, ['category_id' => $category->id]);
    }],
    'psb.info while psb is off' => [function () {
        Setting::set('modules.psb.enabled', false);

        return navItem('psb.info');
    }],
    'psb.register while psb is off' => [function () {
        Setting::set('modules.psb.enabled', false);

        return navItem('psb.register');
    }],
    'psb.status while psb is off' => [function () {
        Setting::set('modules.psb.enabled', false);

        return navItem('psb.status');
    }],
    'letters.verify while dokumen is off' => [function () {
        Setting::set('modules.dokumen.enabled', false);

        return navItem('letters.verify');
    }],
    'javascript url' => [fn () => navItem('url', 'Jahat', ['url' => 'javascript:alert(1)'])],
    'protocol relative url' => [fn () => navItem('url', 'Jahat', ['url' => '//evil.test'])],
    'data url' => [fn () => navItem('url', 'Jahat', ['url' => 'data:text/html,x'])],
    'unknown type' => [fn () => navItem('bogus', 'Aneh')],
    'page without an id' => [fn () => navItem('page')],
    'broken row' => [fn () => 'bukan-item'],
    'group with only unavailable children' => [fn () => navGroup('Profil', [navPage(staticPage(['status' => 'draft'])), navItem('posts')])],
]);

it('AC-6: shows psb.info while psb is on, also when konten is off (F038: the route always exists)', function () {
    navSet([navItem('home'), navItem('psb.info')]);

    expect(navLabels(navResolve()['primary']))->toBe(['Beranda', 'Info PSB']);

    Setting::set('modules.konten.enabled', false);

    expect(navLabels(navResolve()['primary']))->toBe(['Beranda', 'Info PSB'])
        ->and(navResolve()['primary'][1]['url'])->toBe(route('psb.info'));

    Setting::set('modules.konten.enabled', true);

    Setting::set('modules.psb.enabled', false);

    expect(navLabels(navResolve()['primary']))->toBe(['Beranda']);
});

it('AC-6: renders a group with only its available children', function () {
    portalContactData();
    $a = staticPage(['slug' => 'pengurus', 'title' => 'Pengurus']);
    $draft = staticPage(['slug' => 'rancangan', 'status' => 'draft']);
    navSet([navItem('home'), navGroup('Profil Lembaga', [navPage($a), navPage($draft), navPage(StaticPage::findBySlug('profil'))]), navItem('url', 'Luar', ['url' => 'https://contoh.test'])]);
    $nav = navResolve();

    expect(navLabels($nav['primary']))->toBe(['Beranda', ['Profil Lembaga' => ['Pengurus', 'Profil']], 'Luar'])
        ->and($nav['primary'][1])->toHaveKey('active');
});

it('AC-6: drops broken items one by one and keeps the rest', function () {
    $page = staticPage(['slug' => 'pengurus', 'title' => 'Pengurus']);
    Setting::set('portal.navigation_primary', ['rusak', 5, null, ['type' => 'page', 'page_id' => 'abc'], navItem('home'), ['label' => 'tanpa tipe'], navPage($page), navGroup('Bersarang', [navGroup('Dalam', [navItem('home')])])]);
    app()->forgetScopedInstances();
    $nav = navResolve();

    expect(navLabels($nav['primary']))->toBe(['Beranda', 'Pengurus']);
});

it('AC-6: falls back labels (the menu cuts page titles at 30 characters)', function () {
    $title = str_repeat('Panjang', 8); // 56 karakter
    $page = staticPage(['slug' => 'panjang', 'title' => $title]);
    $category = kontenCategory('post', 'Pengumuman Penting');
    portalPost(['category_id' => $category->id]);
    navSet(
        [navPage($page), navPage($page, 'Dengan Label'), navItem('category', null, ['category_id' => $category->id]), navItem('home', 'Awal'), navItem('posts', '  ')],
        navPage($page),
    );
    $nav = navResolve();

    expect(navLabels($nav['primary']))->toBe([Str::limit($title, 30), 'Dengan Label', 'Pengumuman Penting', 'Awal', 'Berita'])
        ->and($nav['headerButton']['label'])->toBe(Str::limit($title, 30));
});

it('AC-8: composes the navigation with at most 3 extra queries and one settings read for profile and navigation', function () {
    navSeedPublicContent();
    $pages = collect(range(1, 4))->map(fn (int $i) => staticPage(['slug' => "p-{$i}", 'title' => "Halaman {$i}"]));
    $categories = collect(range(1, 3))->map(fn (int $i) => kontenCategory('post', "Kategori {$i}"));
    $categories->each(fn ($category) => portalPost(['title' => "Berita {$category->id}", 'category_id' => $category->id]));
    navSet(
        [navItem('home'), ...$pages->map(fn ($page): array => navPage($page))->all(), ...$categories->map(fn ($category): array => navItem('category', null, ['category_id' => $category->id]))->all()],
    );
    app()->forgetScopedInstances();
    Setting::moduleEnabled('konten');
    Setting::moduleEnabled('psb');
    Setting::moduleEnabled('dokumen');
    $profile = null;
    $queries = recordedQueries(function () use (&$profile): void {
        $profile = app(GetSchoolProfile::class)->handle();
        app(GetPortalNavigation::class)->handle($profile);
    });
    $content = array_filter($queries, fn (array $query): bool => ! str_contains($query['sql'], 'settings'));

    expect(queriesOnTable($queries, 'settings'))->toBe(1)->and(count($content))->toBeLessThanOrEqual(3);
});

it('AC-8: composes list checks for posts, albums and videos without per-item queries and none for system targets', function () {
    navSeedPublicContent();
    navSet([navItem('home'), navItem('posts'), navItem('albums'), navItem('videos')]);
    app()->forgetScopedInstances();
    $profile = app(GetSchoolProfile::class)->handle();
    Setting::moduleEnabled('konten');
    Setting::moduleEnabled('psb');
    Setting::moduleEnabled('dokumen');

    $lists = recordedQueries(fn () => app(GetPortalNavigation::class)->handle($profile));

    expect(count($lists))->toBeLessThanOrEqual(3);

    navSet([navItem('home'), navItem('url', 'X', ['url' => '/berita']), navItem('psb.status'), navItem('letters.verify')], navItem('psb.register'));
    app()->forgetScopedInstances();
    $profile = app(GetSchoolProfile::class)->handle();
    Setting::moduleEnabled('konten');
    Setting::moduleEnabled('psb');
    Setting::moduleEnabled('dokumen');

    expect(recordedQueries(fn () => app(GetPortalNavigation::class)->handle($profile)))->toBe([]);
});

it('AC-11 (F038): rejects a page item that points to the psb-info page, whether psb is on or off', function () {
    $page = staticPage(['slug' => 'info-psb', 'title' => 'Informasi PSB', 'template' => 'psb-info']);
    navSet([navItem('home'), navPage($page), navItem('psb.info')]);

    expect(navLabels(navResolve()['primary']))->toBe(['Beranda', 'Info PSB']);

    Setting::set('modules.psb.enabled', false);

    expect(navLabels(navResolve()['primary']))->toBe(['Beranda']);
});
