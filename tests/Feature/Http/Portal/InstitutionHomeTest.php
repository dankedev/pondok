<?php

use App\Models\Album;
use App\Models\Photo;
use App\Models\Setting;
use App\Models\Video;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    portalBoot();
    Setting::set('school.name', "Ma'had Al-Khair");
});

/**
 * Bagian slider (dari kontainer `role="region"` sampai akhir seksinya).
 */
function profilSlider(string $html): string
{
    if (preg_match('#<div\b[^>]*role="region"[^>]*>.*?</section>#is', $html, $match) !== 1) {
        return '';
    }

    return $match[0];
}

it('AC-44: renders the hero, arabic name, highlights heading and highlights', function () {
    profilSet([
        'school.arabic_name' => 'معهد الخير',
        'portal.hero_greeting' => 'Sambutan uji',
        'portal.hero_title' => 'Judul hero uji',
        'portal.hero_subtitle' => 'Subjudul hero uji',
        'portal.highlights_heading' => 'Judul keunggulan uji',
        'portal.highlights' => [
            ['title' => 'Kartu Satu', 'description' => 'Deskripsi satu'],
            ['title' => 'Kartu Dua', 'description' => null],
            ['title' => 'Kartu Tiga', 'description' => 'Deskripsi tiga'],
        ],
    ]);

    $html = $this->get('/')->assertOk()->getContent();
    $text = profilVisibleText($html);

    expect($text)->toContain('Sambutan uji')->toContain('Judul hero uji')->toContain('Subjudul hero uji')
        ->toContain('Judul keunggulan uji')->toContain('Kartu Satu')->toContain('Deskripsi satu')
        ->toContain('Kartu Dua')->toContain('Kartu Tiga')->toContain('Deskripsi tiga')
        ->and($html)->toMatch('/<[a-z0-9]+\b(?=[^>]*\blang="ar")(?=[^>]*\bdir="rtl")[^>]*>\s*معهد الخير/u');
});

it('AC-44: falls back to the name and the tagline when hero title and subtitle are empty', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toMatch('/<h1\b[^>]*>\s*'.preg_quote(e("Ma'had Al-Khair"), '/').'\s*<\/h1>/')
        ->and(profilVisibleText($html))->toContain("Mencetak generasi Qur'ani");
});

it('AC-45: hides the sections whose content was not chosen', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('role="region"')->not->toContain('snap-x')->not->toContain('<iframe')
        ->not->toContain('youtube-nocookie');
});

it('AC-46: renders the first ten album photos in a keyboard accessible slider', function () {
    $album = kontenAlbum(['title' => 'Fasilitas']);

    foreach (range(12, 1) as $order) {
        kontenPhoto($album, ['sort_order' => $order, 'caption' => "Foto {$order}"]);
    }

    $baseline = substr_count($this->get('/')->getContent(), '<script');
    Setting::set('portal.hero_album_id', $album->id);

    $html = $this->get('/')->assertOk()->getContent();
    $slider = profilSlider($html);
    $region = profilTag($html, 'div', ['role="region"']);

    preg_match_all('/<img\b[^>]*>/i', $slider, $imgs);
    $alts = array_map(function (string $img): ?string {
        preg_match('/alt="([^"]*)"/', $img, $m);

        return $m[1] ?? null;
    }, $imgs[0]);

    expect($region)->toContain('tabindex="0"')->toContain('aria-label="Fasilitas"')
        ->and($alts)->toBe(array_map(fn (int $i): string => "Foto {$i}", range(1, 10)))
        ->and(collect($imgs[0])->every(fn (string $img): bool => str_contains($img, 'loading="lazy"')))->toBeTrue()
        ->and($slider)->toContain('snap-x')
        ->and(substr_count($html, '<script'))->toBe($baseline);
});

it('AC-46: gives a photo without caption an alt text', function () {
    $album = kontenAlbum(['title' => 'Fasilitas']);
    kontenPhoto($album, ['sort_order' => 1, 'caption' => null]);
    Setting::set('portal.hero_album_id', $album->id);

    preg_match_all('/<img\b[^>]*>/i', profilSlider($this->get('/')->getContent()), $imgs);

    expect($imgs[0])->toHaveCount(1)->and($imgs[0][0])->toMatch('/alt="[^"]+"/');
});

it('AC-46: skips the slider and the profile video and runs no content query when the content module is off', function () {
    $album = portalAlbumWithPhotos(3, ['title' => 'Fasilitas']);
    $video = kontenVideo(['title' => 'Video Profil Uji']);
    profilSet(['portal.hero_album_id' => $album->id, 'portal.profile_video_id' => $video->id]);
    Setting::set('modules.konten.enabled', false);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $html = $this->get('/')->assertOk()->getContent();
    $contentQueries = array_filter($queries, fn (string $sql): bool => preg_match('/\b(from|join)\s+["`]?(albums|photos|videos|pages|posts)\b/i', $sql) === 1);

    expect($html)->not->toContain('role="region"')->not->toContain('Video Profil Uji')->not->toContain('<iframe')
        ->and($contentQueries)->toBe([]);
});

it('AC-47: shows the profile video and the psb video with their titles', function () {
    $profile = kontenVideo(['title' => 'Video Profil Uji', 'youtube_id' => 'AAAAAAAAAAA']);
    $psb = kontenVideo(['title' => 'Video PSB Uji', 'youtube_id' => 'BBBBBBBBBBB']);
    profilSet(['portal.profile_video_id' => $profile->id, 'portal.psb_video_id' => $psb->id, 'modules.psb.enabled' => true]);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('youtube-nocookie.com/embed/AAAAAAAAAAA')->toContain('youtube-nocookie.com/embed/BBBBBBBBBBB')
        ->and(profilVisibleText($html))->toContain('Video Profil Uji')->toContain('Video PSB Uji');
});

it('AC-47: hides the psb video when the psb module is off but keeps the profile video', function () {
    $profile = kontenVideo(['title' => 'Video Profil Uji', 'youtube_id' => 'AAAAAAAAAAA']);
    $psb = kontenVideo(['title' => 'Video PSB Uji', 'youtube_id' => 'BBBBBBBBBBB']);
    profilSet(['portal.profile_video_id' => $profile->id, 'portal.psb_video_id' => $psb->id, 'modules.psb.enabled' => false]);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('AAAAAAAAAAA')->not->toContain('BBBBBBBBBBB')->not->toContain('Video PSB Uji');
});

it('AC-47: renders the home page without the section when the chosen album or video was deleted', function () {
    $album = portalAlbumWithPhotos(2, ['title' => 'Fasilitas']);
    $video = kontenVideo(['title' => 'Video Profil Uji', 'youtube_id' => 'AAAAAAAAAAA']);
    profilSet(['portal.hero_album_id' => $album->id, 'portal.profile_video_id' => $video->id, 'portal.psb_video_id' => $video->id, 'modules.psb.enabled' => true]);

    Photo::query()->delete();
    Album::query()->delete();
    Video::query()->delete();

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('role="region"')->not->toContain('<iframe')->not->toContain('Video Profil Uji');
});

it('AC-51: escapes the free text fields of the home page', function () {
    $script = '<script>alert(1)</script>';
    profilSet([
        'portal.hero_title' => $script,
        'portal.highlights_heading' => $script,
        'portal.highlights' => [['title' => 'Kartu', 'description' => $script]],
        'school.contacts' => [profilContact(['label' => $script])],
        'portal.footer_text' => ['title' => $script, 'body' => $script],
    ]);
    navSet([navItem('url', $script, ['url' => '/berita'])], [navItem('url', $script, ['url' => '/berita'])]);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)')->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
});

it('AC-52: stays stateless with every profile field filled', function () {
    $album = portalAlbumWithPhotos(2, ['title' => 'Fasilitas']);
    $video = kontenVideo();
    profilSet([
        'portal.hero_title' => 'Judul', 'portal.highlights' => [['title' => 'A', 'description' => null]],
        'portal.hero_album_id' => $album->id, 'portal.profile_video_id' => $video->id,
        'school.contacts' => [profilContact()], 'school.map_url' => 'https://maps.app.goo.gl/abc',
    ]);

    $response = $this->get('/')->assertOk();

    expect($response->headers->getCookies())->toBeEmpty()->and($response->headers->has('Set-Cookie'))->toBeFalse();
});
