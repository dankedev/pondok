<?php

use App\Models\Category;
use App\Models\Setting;
use App\Models\StaticPage;

beforeEach(function () {
    portalBoot();
});

it('AC-19: lists albums with photos only and filters by category', function () {
    $fasilitas = Category::query()->where('slug', 'fasilitas')->firstOrFail();
    $kegiatan = Category::query()->where('slug', 'kegiatan')->firstOrFail();
    portalAlbumWithPhotos(3, ['title' => 'Asrama Baru', 'category_id' => $fasilitas->id]);
    portalAlbumWithPhotos(1, ['title' => 'Lomba Pidato', 'category_id' => $kegiatan->id]);
    kontenAlbum(['title' => 'Album Tanpa Foto']);

    $html = $this->get('/galeri')->assertOk()->getContent();

    expect($html)->toContain('Asrama Baru')->toContain('Lomba Pidato')->not->toContain('Album Tanpa Foto')
        ->toContain('Fasilitas')->toContain('3 foto')
        ->toMatch('/<img[^>]*loading="lazy"/');

    $this->get('/galeri?kategori=fasilitas')->assertOk()->assertSee('Asrama Baru')->assertDontSee('Lomba Pidato');
});

it('AC-19: shows an empty state without albums', function () {
    kontenAlbum(['title' => 'Album Tanpa Foto']);

    $this->get('/galeri')->assertOk()->assertSeeText(__('pondok.portal.albums.empty'));
});

it('AC-20: shows album photos in order with alt text and captions', function () {
    $album = kontenAlbum(['title' => 'Asrama Baru', 'description' => '<b>Deskripsi</b> album']);
    $p2 = kontenPhoto($album, ['sort_order' => 2, 'caption' => 'Foto kedua']);
    $p1 = kontenPhoto($album, ['sort_order' => 1]);
    $p3 = kontenPhoto($album, ['sort_order' => 3]);

    $response = $this->get('/galeri/asrama-baru')->assertOk();
    $html = $response->getContent();

    expect($html)->toMatch('/<h1[^>]*>\s*Asrama Baru\s*<\/h1>/')
        ->toContain('&lt;b&gt;Deskripsi&lt;/b&gt; album')
        ->toContain('<figcaption')
        ->toContain('Foto kedua')
        ->and(substr_count($html, 'src="'.$p1->url().'"'))->toBe(1)
        ->and(strpos($html, $p1->url()))->toBeLessThan(strpos($html, $p2->url()))
        ->and(strpos($html, $p2->url()))->toBeLessThan(strpos($html, $p3->url()));

    preg_match_all('/<img\b[^>]*>/i', $html, $imgs);
    $photoImgs = array_filter($imgs[0], fn (string $tag): bool => str_contains($tag, '/albums/'));
    expect($photoImgs)->toHaveCount(3);
    foreach ($photoImgs as $tag) {
        expect($tag)->toMatch('/\balt="[^"]+"/');
    }
});

it('AC-20: paginates album photos 24 per page', function () {
    $album = portalAlbumWithPhotos(30, ['title' => 'Album Besar']);

    $first = $this->get('/galeri/album-besar')->assertOk()->getContent();
    $second = $this->get('/galeri/album-besar?page=2')->assertOk()->getContent();

    $count = fn (string $html): int => preg_match_all('/<figure\b/i', $html);
    expect($count($first))->toBe(24)->and($count($second))->toBe(6);
});

it('AC-21: lists videos as nocookie iframes with titles', function () {
    kontenVideo(['title' => 'Video Alpha <i>', 'youtube_id' => 'AAAAAAAAAAA', 'description' => '<b>Uraian</b>', 'sort_order' => 2]);
    kontenVideo(['title' => 'Video Beta', 'youtube_id' => 'BBBBBBBBBBB', 'sort_order' => 1]);

    $html = $this->get('/video')->assertOk()->getContent();

    expect($html)->toContain('https://www.youtube-nocookie.com/embed/AAAAAAAAAAA')
        ->toMatch('/<iframe[^>]*title="Video Alpha &lt;i&gt;"/')
        ->toContain('&lt;b&gt;Uraian&lt;/b&gt;')
        ->and(strpos($html, 'BBBBBBBBBBB'))->toBeLessThan(strpos($html, 'AAAAAAAAAAA'));
});

it('AC-21: shows an empty state without videos and paginates 9 per page', function () {
    $this->get('/video')->assertOk()->assertSeeText(__('pondok.portal.videos.empty'));

    foreach (range(1, 10) as $i) {
        kontenVideo(['title' => "Video {$i}", 'youtube_id' => sprintf('vid%08d', $i), 'sort_order' => $i]);
    }

    expect(substr_count($this->get('/video')->getContent(), '<iframe'))->toBe(9);
});

it('AC-22: renders static pages and contact info', function () {
    StaticPage::findBySlug('profil')->update(['body' => '<h2>Sejarah</h2><p>Isi</p><script>alert(1)</script>']);

    $profil = $this->get('/profil')->assertOk();
    expect($profil->getContent())->toMatch('/<h1[^>]*>\s*Profil\s*<\/h1>/')
        ->toContain('<h2>Sejarah</h2>')->not->toContain('<script>alert(1)');

    StaticPage::findBySlug('visi-misi')->update(['body' => '']);
    $this->get('/visi-misi')->assertOk()->assertSeeText(__('pondok.portal.pages.empty'));

    StaticPage::findBySlug('kontak')->update(['body' => '']);
    Setting::set('school.phone', '081234567890');
    Setting::set('school.contacts', [['key' => 'abcd1234', 'label' => 'WhatsApp', 'name' => null, 'phone' => '+6281234567890', 'is_whatsapp' => true]]);
    Setting::set('school.email', 'info@alkhair.test');

    $kontak = $this->get('/kontak')->assertOk();
    expect($kontak->getContent())->not->toContain(__('pondok.portal.pages.empty'))
        ->toContain('0812-3456-7890')->toContain('href="tel:081234567890"')
        ->toContain('href="https://wa.me/6281234567890"')->toContain('href="mailto:info@alkhair.test"');

    $this->get('/beranda')->assertNotFound();
});
