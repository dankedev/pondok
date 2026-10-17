<?php

use App\Models\Setting;

/*
| Identitas lembaga di beranda berbasis blok (F045). Blok hero, media, dan feed diuji di `HomeBlocksTest`.
*/

beforeEach(function () {
    portalBoot();
    Setting::set('school.name', "Ma'had Al-Khair");
});

it('AC-8: shows the institution name and tagline without hero and never renders the arabic name on the home', function () {
    Setting::set('school.arabic_name', 'معهد الخير');

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toMatch('/<h1\b[^>]*>\s*'.preg_quote(e("Ma'had Al-Khair"), '/').'\s*<\/h1>/')
        ->and(profilVisibleText($html))->toContain("Mencetak generasi Qur'ani")
        ->and($html)->not->toContain('معهد الخير');
});

it('AC-5: escapes the free text fields of the home page', function () {
    $script = '<script>alert(1)</script>';
    profilSet([
        'school.contacts' => [profilContact(['label' => $script])],
        'portal.footer_text' => ['title' => $script, 'body' => $script],
    ]);
    blkSet([blk('hero', ['title' => $script, 'eyebrow' => $script, 'text' => $script]), blk('features', ['heading' => $script, 'items' => [['title' => 'Kartu', 'text' => $script]]])]);
    navSet([navItem('url', $script, ['url' => '/berita'])], [navItem('url', $script, ['url' => '/berita'])]);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)')->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
});

it('AC-7: stays stateless with every block type filled', function () {
    $album = portalAlbumWithPhotos(2, ['title' => 'Fasilitas']);
    $video = kontenVideo();
    portalSeedContent();
    profilSet(['school.contacts' => [profilContact()], 'school.map_url' => 'https://maps.app.goo.gl/abc']);
    blkSet([
        blk('hero', ['title' => 'Judul', 'album_id' => $album->id]), blk('text_image', ['heading' => 'T', 'text' => '<p>x</p>']),
        blk('features', ['items' => [['title' => 'A']]]),
        blk('quote', ['text' => 'q']), blk('cta', ['heading' => 'c', 'variant' => 'gold']), blk('video', ['video_id' => $video->id]),
        blk('feed', ['source' => 'posts']),
    ]);

    $response = $this->get('/')->assertOk();

    expect($response->headers->getCookies())->toBeEmpty()->and($response->headers->has('Set-Cookie'))->toBeFalse();
});
