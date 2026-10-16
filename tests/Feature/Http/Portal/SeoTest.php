<?php

use App\Actions\Konten\GetSchoolProfile;
use App\Models\Setting;
use App\Models\StaticPage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    portalBoot();
});

it('AC-23: sets home meta tags', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)
        ->toContain('<title>Ma&#039;had Al-Khair — Mencetak generasi Qur&#039;ani</title>')
        ->toMatch('/<meta name="description" content="Mencetak generasi Qur(&#039;|\')ani"/')
        ->toContain('<link rel="canonical" href="'.url('/').'"')
        ->toContain('property="og:site_name"')->toContain('property="og:title"')
        ->toContain('content="website"')->toContain('content="id_ID"')
        ->not->toContain('og:image');

    Storage::disk('public')->put('school/logo.png', 'x');
    Setting::set('school.logo_path', 'school/logo.png');
    $logo = app(GetSchoolProfile::class)->handle()['logo_url'];
    expect($this->get('/')->getContent())->toContain('property="og:image" content="'.$logo.'"');

    Setting::set('school.tagline', '');
    expect($this->get('/')->getContent())->toContain(e(__('pondok.portal.meta.default_description', ['name' => "Ma'had Al-Khair"])));
});

it('AC-24: sets article meta tags', function () {
    Storage::fake('public', ['url' => 'https://alkhair.sch.id/storage']);
    portalPost(['title' => 'Wisuda Akbar', 'excerpt' => 'Ringkasan wisuda', 'cover_path' => 'posts/covers/w.jpg']);
    portalPost(['title' => 'Tanpa Excerpt', 'excerpt' => null, 'body' => '<p>Halo</p><p>Dunia</p>']);

    $html = $this->get('/berita/wisuda-akbar?utm_source=x')->assertOk()->getContent();

    expect($html)
        ->toContain('<title>Wisuda Akbar — Ma&#039;had Al-Khair</title>')
        ->toContain('name="description" content="Ringkasan wisuda"')
        ->toContain('content="article"')
        ->toContain('property="og:image" content="https://alkhair.sch.id/storage/posts/covers/w.jpg"')
        ->toContain('<link rel="canonical" href="'.route('portal.posts.show', 'wisuda-akbar').'"')
        ->not->toContain('utm_source');

    expect($this->get('/berita/tanpa-excerpt')->getContent())->toContain('name="description" content="Halo Dunia"');

    config(['app.url' => 'https://alkhair.sch.id']);
    $evil = $this->withHeader('Host', 'evil.test')->get('/berita/wisuda-akbar')->assertOk()->getContent();
    expect($evil)->toContain('<link rel="canonical" href="https://alkhair.sch.id/berita/wisuda-akbar"')
        ->toContain('property="og:url" content="https://alkhair.sch.id/berita/wisuda-akbar"')
        ->not->toContain('evil.test');
});

it('AC-24a: builds pagination and canonical urls from APP_URL', function () {
    config(['app.url' => 'https://alkhair.sch.id']);
    foreach (range(1, 10) as $i) {
        portalPost(['title' => "Kabar {$i}"]);
    }
    portalAlbumWithPhotos(30, ['title' => 'Album Besar']);

    $posts = $this->withHeader('Host', 'evil.test')->get('/berita?jenis=berita')->assertOk();
    expect(portalDecoded($posts))->toContain('https://alkhair.sch.id/berita?jenis=berita&page=2');
    expect($posts->getContent())->not->toContain('evil.test');

    $album = $this->withHeader('Host', 'evil.test')->get('/galeri/album-besar')->assertOk();
    expect(portalDecoded($album))->toContain('https://alkhair.sch.id/galeri/album-besar?page=2');
    expect($album->getContent())->not->toContain('evil.test');
});

it('AC-24a: tolerates APP_URL without scheme', function () {
    config(['app.url' => 'alkhair.sch.id']);

    $this->get('/')->assertOk()->assertSee('<link rel="canonical" href="http://alkhair.sch.id"', false);
    $this->get('/berita')->assertOk();
});

it('AC-25: builds the sitemap', function () {
    $c = portalSeedContent();
    portalPost(['title' => 'Terbit Satu']);
    kontenPost(['title' => 'Draf Sitemap']);
    portalPost(['title' => 'Jadwal Sitemap', 'published_at' => CarbonImmutable::parse('2026-10-20 08:00', 'Asia/Jakarta')]);
    portalPost(['title' => 'Hapus Sitemap'])->delete();
    kontenAlbum(['title' => 'Kosong Sitemap']);
    StaticPage::findBySlug('profil')->update(['body' => '<p>Isi</p>']);
    StaticPage::findBySlug('visi-misi')->update(['body' => '']);

    $response = $this->get('/sitemap.xml')->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('application/xml');

    $xml = simplexml_load_string($response->getContent());
    expect($xml)->not->toBeFalse();
    $locs = [];
    foreach ($xml->url as $url) {
        $locs[(string) $url->loc] = (string) $url->lastmod;
    }

    expect($locs)->toHaveKeys([
        route('portal.home'), route('portal.posts.index'), route('portal.albums.index'), route('portal.videos.index'),
        route('portal.posts.show', 'wisuda-2026'), route('portal.albums.show', 'album-ramadan'), route('portal.pages.show', 'profil'),
    ])->not->toHaveKeys([
        route('portal.posts.show', 'draf-sitemap'), route('portal.posts.show', 'jadwal-sitemap'), route('portal.posts.show', 'hapus-sitemap'),
        route('portal.albums.show', 'kosong-sitemap'), route('portal.pages.show', 'visi-misi'),
    ]);
    expect($locs[route('portal.posts.show', 'wisuda-2026')])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
});

it('AC-25: reduces the sitemap when konten is off and adds psb when on', function () {
    portalSeedContent();
    portalPsbOpen();
    Setting::set('modules.konten.enabled', false);

    $xml = simplexml_load_string($this->get('/sitemap.xml')->getContent());
    $locs = array_map(fn ($u) => (string) $u->loc, iterator_to_array($xml->url, false));

    expect($locs)->toEqualCanonicalizing([route('portal.home'), route('psb.register')]);
});

it('AC-25: builds sitemap and robots urls from APP_URL regardless of Host', function () {
    portalSeedContent();
    config(['app.url' => 'https://alkhair.sch.id']);

    $sitemap = $this->withHeader('Host', 'evil.test')->get('/sitemap.xml')->assertOk()->getContent();
    $xml = simplexml_load_string($sitemap);
    foreach ($xml->url as $url) {
        expect((string) $url->loc)->toStartWith('https://alkhair.sch.id');
    }
    expect($sitemap)->not->toContain('evil.test');

    $robots = $this->withHeader('Host', 'evil.test')->get('/robots.txt')->assertOk();
    expect($robots->getContent())->toContain('Sitemap: https://alkhair.sch.id/sitemap.xml')->not->toContain('evil.test');
});

it('AC-26: serves robots.txt', function () {
    $response = $this->get('/robots.txt')->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/plain');
    expect($response->getContent())->toContain('Disallow: /admin')->toContain('Disallow: /psb/status')
        ->toContain('Sitemap: '.rtrim(config('app.url'), '/').'/sitemap.xml');
    expect(public_path('robots.txt'))->not->toBeFile();
});
