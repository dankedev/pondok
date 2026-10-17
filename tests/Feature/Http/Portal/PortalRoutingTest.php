<?php

use App\Models\Setting;
use Carbon\CarbonImmutable;

beforeEach(function () {
    portalBoot();
});

it('AC-1: renders the home page for guests', function () {
    $response = $this->get('/')->assertOk();

    expect($response->getContent())
        ->toMatch('/<title>[^<]*Ma&#039;had Al-Khair|<title>[^<]*Ma\'had Al-Khair/')
        ->toMatch('/<h1[^>]*>[^<]*Ma(&#039;|\')had Al-Khair/')
        ->toContain('Mencetak generasi Qur');
    expect(route('portal.home'))->toBe(url('/'));
    expect(resource_path('views/welcome.blade.php'))->not->toBeFile();
});

it('AC-2: hides content routes with 404 when konten is disabled', function (string $url) {
    portalSeedContent();
    Setting::set('modules.konten.enabled', false);

    $this->get($url)->assertNotFound();
})->with(['/berita', '/berita/wisuda-2026', '/galeri', '/galeri/album-ramadan', '/video', '/profil', '/visi-misi', '/kontak']);

it('AC-2: serves content routes when konten is enabled', function (string $url) {
    portalSeedContent();
    portalContactData(); // F037: /kontak hanya tersedia bila profil punya data kontak

    $this->get($url)->assertOk();
})->with(['/berita', '/berita/wisuda-2026', '/galeri', '/galeri/album-ramadan', '/video', '/profil', '/visi-misi', '/kontak']);

it('AC-2: keeps home, sitemap and robots when konten is disabled and hides content links', function () {
    portalSeedContent();
    Setting::set('modules.konten.enabled', false);

    $home = $this->get('/')->assertOk();
    $this->get('/sitemap.xml')->assertOk();
    $this->get('/robots.txt')->assertOk();

    $home->assertDontSee(route('portal.posts.index'), false)
        ->assertDontSee(route('portal.albums.index'), false)
        ->assertDontSee(route('portal.videos.index'), false)
        ->assertDontSee('Wisuda 2026');
});

it('AC-3: keeps 403 for module middleware without status', function () {
    Setting::set('modules.psb.enabled', false);

    $this->get('/psb')->assertForbidden();
});

it('AC-4: returns 404 for unpublished posts', function (Closure $make, ?string $travel) {
    $slug = $make();
    if ($travel !== null) {
        $this->travelTo(CarbonImmutable::parse($travel, 'Asia/Jakarta'));
    }

    $this->get("/berita/{$slug}")->assertStatus($travel !== null ? 200 : 404);
})->with([
    'slug tidak ada' => [fn () => 'tidak-ada', null],
    'draft' => [fn () => kontenPost(['title' => 'Draf'])->slug, null],
    'terjadwal' => [fn () => portalPost(['title' => 'Terjadwal', 'published_at' => CarbonImmutable::parse('2026-10-20 08:00', 'Asia/Jakarta')])->slug, null],
    'terhapus' => [function () {
        $post = portalPost(['title' => 'Terhapus']);
        $post->delete();

        return $post->slug;
    }, null],
    'huruf besar' => [function () {
        portalPost(['title' => 'Wisuda']);

        return 'Wisuda';
    }, null],
    'terjadwal setelah waktunya' => [fn () => portalPost(['title' => 'Nanti', 'published_at' => CarbonImmutable::parse('2026-10-20 08:00', 'Asia/Jakarta')])->slug, '2026-10-20 08:00'],
]);

it('AC-5: renders a safe 404 page', function (string $url) {
    $response = $this->get($url)->assertNotFound();

    expect($response->getContent())
        ->toContain(__('pondok.portal.errors.not_found_title'))
        ->toContain('<meta name="robots" content="noindex">')
        ->toContain(route('portal.home'))
        ->toContain('lang="id"')
        ->not->toContain('NotFoundHttpException')
        ->not->toContain('vendor/')
        ->not->toContain('app/');
})->with(['/berita/tidak-ada', '/tidak-ada-halaman']);

it('AC-6: rejects invalid filters with 404', function (string $query) {
    portalPost();
    kontenCategory('album', 'Khusus Album', 'khusus-album');

    $this->get("/berita?{$query}")->assertNotFound();
})->with([
    'kategori=tidak-ada', 'kategori=khusus-album', 'jenjang=smk', 'page=5',
    'kategori[]=a', 'kategori[x]=1', 'jenjang[]=sltp',
    'page=abc', 'page=0', 'page=-1', 'page[]=2', 'page=1.5', 'page=1234567',
]);

it('AC-7: ignores the removed jenis parameter and keeps it out of canonical urls and links', function (string $query) {
    foreach (range(1, 10) as $i) {
        portalPost(['title' => sprintf('Kabar nomor %02d', $i), 'published_at' => CarbonImmutable::parse('2026-10-14 08:00', 'Asia/Jakarta')->subMinutes($i)]);
    }

    $response = $this->get("/berita?{$query}")->assertOk()->assertSee('Kabar nomor 01')->assertSeeText(__('pondok.portal.posts.titles.all'));

    expect(portalDecoded($response))->not->toContain('jenis=')->and($response->getContent())->not->toContain('Saring menurut jenis');
})->with(['jenis=artikel', 'jenis=prestasi', 'jenis[]=berita']);

it('AC-6: accepts empty and first page and unit codes in any case', function (string $query) {
    $this->get("/berita?{$query}")->assertOk()->assertSeeText(__('pondok.portal.posts.empty'));
})->with(['page=1', 'page=', 'jenjang=sltp', 'jenjang=SLTP']);

it('AC-6a: rejects invalid gallery and video queries with 404', function (string $url) {
    kontenCategory('post', 'Berita Umum', 'berita-umum');
    kontenAlbum(['title' => 'Kosong']);

    $this->get($url)->assertNotFound();
})->with(['/galeri?kategori=berita-umum', '/galeri?kategori[]=a', '/galeri?page=abc', '/galeri/kosong', '/video?page=0']);

it('AC-35: renders the global 404 page outside portal routes', function (string $url) {
    Setting::set('modules.psb.enabled', true);

    $response = $this->get($url)->assertNotFound();

    expect($response->getContent())
        ->toContain(__('pondok.portal.errors.not_found_title'))
        ->toContain('<html lang="id"')
        ->not->toContain('aria-current="page"')
        ->not->toContain('NotFoundHttpException');
})->with(['/tidak-ada-halaman', '/psb/tidak-ada']);

it('AC-36: shows a plain login link to the panel in the header of every portal page', function (string $url) {
    portalSeedContent();
    portalContactData(); // F037: /kontak hanya tersedia bila profil punya data kontak
    $login = route('filament.admin.auth.login');

    $response = $this->get($url)->assertOk();
    $html = $response->getContent();

    expect($html)->toContain('href="'.$login.'"')
        ->toContain(__('pondok.portal.nav.login'))
        ->not->toContain('pondok.portal.nav.login');
    expect(preg_match('/<header\b.*?href="'.preg_quote($login, '/').'".*?<\/header>/s', $html))->toBe(1);
    expect($response->headers->getCookies())->toBeEmpty();
})->with(['/', '/berita', '/berita/wisuda-2026', '/galeri', '/video', '/kontak']);
