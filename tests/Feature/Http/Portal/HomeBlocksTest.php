<?php

use App\Models\Album;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
| F045 AC-5 (saat baca), AC-7 sampai AC-12. Kontrak render: `GET /` memakai `portal.home` yang mengiterasi blok
| `portal.home_blocks`; setiap blok dirender sebagai satu `<section>` (blok `hero` juga `<section>` dengan `<h1>`),
| judul seksi `<h2>`. Komponen `x-portal.adaptive-grid :count :max` memuat kelas literal `lg:grid-cols-{n}`.
| Judul bawaan blok `feed` memakai kunci yang sudah ada: `pondok.portal.home.latest_news|latest_achievements|featured_videos|latest_albums`;
| "Lihat semua" memakai `see_all` dan aria-label `see_all_label`.
*/

beforeEach(function () {
    portalBoot();
});

/**
 * `<section>` pertama yang memuat `$needle` (kosong bila tidak ada).
 */
function blkSection(string $html, string $needle): string
{
    preg_match_all('#<section\b.*?</section>#is', $html, $matches);

    foreach ($matches[0] as $section) {
        if (str_contains($section, $needle)) {
            return $section;
        }
    }

    return '';
}

it('AC-7: renders the blocks in the saved order with one h1 and h2 section headings', function () {
    kontenAlbum();
    blkSet([
        blk('cta', ['heading' => 'Ajakan Pertama', 'variant' => 'light']),
        blk('hero', ['title' => 'Judul Hero Uji']),
        blk('features', ['heading' => 'Keunggulan Kami', 'items' => [['title' => 'Satu', 'text' => 'Deskripsi satu']]]),
        blk('quote', ['text' => 'Teks kutipan uji']),
    ]);

    $html = blkHome();
    $positions = array_map(fn (string $needle): int|false => mb_strpos($html, $needle), ['Ajakan Pertama', 'Judul Hero Uji', 'Keunggulan Kami', 'Teks kutipan uji']);

    expect($positions)->not->toContain(false)->and($positions)->toBe(collect($positions)->sort()->values()->all())
        ->and(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toMatch('#<h1\b[^>]*>\s*Judul Hero Uji\s*</h1>#')
        ->toMatch('#<h2\b[^>]*>\s*Keunggulan Kami\s*</h2>#')->toMatch('#<h2\b[^>]*>\s*Ajakan Pertama\s*</h2>#')
        ->and(profilVisibleText($html))->toContain('Deskripsi satu');
});

it('AC-7: skips hidden, empty and dangling blocks without an error', function () {
    $video = kontenVideo();
    $deletedVideo = kontenVideo();
    $deletedVideo->delete();
    blkSet([
        blk('cta', ['heading' => 'Tersembunyi Uji', 'variant' => 'light'], visible: false),
        blk('text_image', ['heading' => '', 'text' => '']),
        blk('video', ['video_id' => $deletedVideo->id, 'heading' => 'Video Hilang Uji']),
        blk('feed', ['source' => 'posts']),
        blk('feed', ['source' => 'albums']),
        blk('hero', ['title' => 'Hero Tersembunyi Uji'], visible: false),
        blk('cta', ['heading' => 'Tetap Tampil', 'variant' => 'light']),
    ]);

    $html = blkHome();

    expect($html)->toContain('Tetap Tampil')
        ->not->toContain('Tersembunyi Uji')->not->toContain('Video Hilang Uji')->not->toContain('<iframe')
        ->not->toContain(__('pondok.portal.home.latest_news'))->not->toContain(__('pondok.portal.home.latest_albums'))
        ->and($video->exists)->toBeTrue();
});

it('AC-7: shows only the institution ribbon and the footer when there are no blocks', function () {
    portalSeedContent();

    $html = blkHome();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toMatch('#<h1\b[^>]*>\s*'.preg_quote(e("Ma'had Al-Khair"), '#').'\s*</h1>#')
        ->and(profilVisibleText($html))->toContain("Mencetak generasi Qur'ani")
        ->and($html)->not->toContain('<h2')->not->toContain('<iframe')->not->toContain(__('pondok.portal.home.latest_news'))
        ->and(profilFooter($html))->not->toBe('');
});

it('AC-7: stored data that is not a list of blocks is treated as no blocks', function () {
    $junk = ['x', 5, null, ['type' => 'cta'], ['type' => 'tidak-ada', 'visible' => true, 'data' => []], ['type' => 'cta', 'visible' => true, 'data' => 'x']];

    foreach (['teks', 12] as $value) {
        profilRawSet('portal.home_blocks', $value);
        app()->forgetScopedInstances();

        expect(blkHome())->not->toContain('<h2');
    }

    foreach ([$junk, [...$junk, blk('cta', ['heading' => 'Sah setelah rusak', 'variant' => 'light'])]] as $index => $value) {
        profilRawSet('portal.home_blocks', $value);
        app()->forgetScopedInstances();

        $html = blkHome();

        expect(substr_count($html, '<h1'))->toBe(1);

        if ($index === 1) {
            expect($html)->toContain('Sah setelah rusak');
        }
    }
});

it('AC-8: renders the hero with eyebrow, title, text, image with alt, and the name fallback', function () {
    Setting::set('school.arabic_name', 'معهد الخير');
    blkSet([blk('hero', [
        'eyebrow' => 'Label kecil uji', 'title' => 'Judul hero uji', 'text' => "Baris satu\nBaris dua",
        'image' => 'portal/blocks/hero.jpg', 'image_alt' => 'Gedung utama uji',
    ])]);

    $html = blkHome();
    $hero = blkSection($html, 'Judul hero uji');

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and(profilVisibleText($hero))->toContain('Label kecil uji')->toContain('Judul hero uji')
        ->and($hero)->toMatch('/Baris satu(\n|<br\s*\/?>)\s*Baris dua/')
        ->and($hero)->toContain(Storage::disk('public')->url('portal/blocks/hero.jpg'))->toContain('alt="Gedung utama uji"')
        ->and($html)->not->toContain('معهد الخير');

    blkSet([blk('hero', ['eyebrow' => 'Label'])]);

    expect(blkHome())->toMatch('#<h1\b[^>]*>\s*'.preg_quote(e("Ma'had Al-Khair"), '#').'\s*</h1>#');
});

it('AC-8: renders the hero album as a slider of at most ten photos inside the hero', function () {
    $album = portalAlbumWithPhotos(12, ['title' => 'Fasilitas Uji']);
    blkSet([blk('hero', ['title' => 'Judul hero uji', 'album_id' => $album->id]), blk('cta', ['heading' => 'Sesudah hero', 'variant' => 'light'])]);

    $hero = blkSection(blkHome(), 'Judul hero uji');
    preg_match('#<div\b[^>]*role="region"[^>]*>.*?</div>#is', $hero, $region);
    preg_match_all('/<img\b[^>]*alt="[^"]+"[^>]*>/i', $region[0] ?? '', $images);

    expect($hero)->toContain('role="region"')->toContain('aria-label="Fasilitas Uji"')->and($images[0])->toHaveCount(10);

    Album::query()->delete();

    expect(blkHome())->not->toContain('role="region"');
});

it('AC-8: shows register button and badge only while a wave is open and drops unavailable buttons', function () {
    $page = staticPage(['slug' => 'pengurus']);
    blkSet([blk('hero', [
        'title' => 'Judul hero uji',
        'primary' => navItem('psb.register', 'Daftar sekarang uji'), 'secondary' => navPage($page, 'Pengurus uji'),
    ])]);

    $closed = blkHome();

    expect(blkSection($closed, 'Judul hero uji'))->not->toContain('href="'.route('psb.register').'"')->and($closed)->not->toContain('Daftar sekarang uji')->not->toContain(__('pondok.portal.home.psb_open_badge'))
        ->and(blkSection($closed, 'Judul hero uji'))->toContain(route('portal.pages.show', 'pengurus'))->toContain('Pengurus uji');

    portalPsbOpen();
    $open = blkSection(blkHome(), 'Judul hero uji');

    expect($open)->toContain('Daftar sekarang uji')->toContain(route('psb.register'))->toContain(__('pondok.portal.home.psb_open_badge'));

    $page->delete();
    blkSet([blk('hero', ['title' => 'Judul hero uji', 'primary' => navPage($page, 'Hilang uji')])]);

    expect(blkHome())->not->toContain('Hilang uji');
});

it('AC-9: renders the call to action variants with design token backgrounds and no accent text', function () {
    portalPost();
    foreach (['light' => [null, ['bg-primary-950', 'bg-accent-500']], 'navy' => ['bg-primary-950', ['bg-accent-500']], 'gold' => ['bg-accent-500', ['bg-primary-950']]] as $variant => [$expected, $forbidden]) {
        blkSet([blk('cta', ['eyebrow' => 'Label ajak', 'heading' => "Ajakan {$variant} uji", 'text' => 'Teks ajakan', 'variant' => $variant, 'primary' => navItem('posts', 'Baca berita')])]);

        $section = blkSection(blkHome(), "Ajakan {$variant} uji");

        expect($section)->not->toBe('')->toContain('Baca berita')->not->toMatch('/text-accent-\d/');

        if ($expected !== null) {
            expect($section)->toMatch('/class="[^"]*\b'.preg_quote($expected, '/').'\b/');
        }

        foreach ($forbidden as $class) {
            expect($section)->not->toMatch('/class="[^"]*\b'.preg_quote($class, '/').'\b/', "{$variant} memuat {$class}");
        }
    }

    blkSet([blk('cta', ['heading' => 'Ajakan navy uji', 'variant' => 'navy', 'primary' => navItem('posts', 'Baca berita')])]);

    expect(blkSection(blkHome(), 'Ajakan navy uji'))->toContain('text-white')->toContain('ring-accent-400');

    blkSet([blk('cta', ['heading' => 'Ajakan gold uji', 'variant' => 'gold'])]);

    expect(blkSection(blkHome(), 'Ajakan gold uji'))->toContain('text-primary-950');
});

it('AC-9: renders the quote right to left with translation and source, and skips what is empty', function () {
    blkSet([blk('quote', ['text' => 'وَلْتَكُن مِّنكُمْ أُمَّةٌ', 'translation' => 'Hendaklah ada di antara kalian segolongan umat', 'source' => 'QS. Ali Imran: 104', 'rtl' => true])]);

    $rtl = blkSection(blkHome(), 'وَلْتَكُن');

    expect($rtl)->toMatch('/(?=[^>]*\bdir="rtl")(?=[^>]*\blang="ar")<[a-z0-9]+\b[^>]*>/')->toContain('font-arabic')
        ->and(profilVisibleText($rtl))->toContain('Hendaklah ada di antara kalian')->toContain('QS. Ali Imran: 104');

    blkSet([blk('quote', ['text' => 'Ilmu itu cahaya', 'rtl' => false])]);

    $ltr = blkSection(blkHome(), 'Ilmu itu cahaya');

    expect($ltr)->not->toContain('dir="rtl"')->not->toContain('lang="ar"')
        ->and(preg_match('#<(p|cite|figcaption|footer|span)\b[^>]*>\s*</\1>#', $ltr))->toBe(0);
});

it('AC-10: feeds show a fixed number of the latest items per source with default headings and always a see all link', function () {
    foreach (range(1, 7) as $i) {
        portalPost(['title' => "Kabar harian {$i}", 'type' => 'berita', 'published_at' => CarbonImmutable::parse('2026-10-14 08:00', 'Asia/Jakarta')->subDays($i)]);
    }
    foreach (range(1, 4) as $i) {
        portalPost(['title' => "Juara Olimpiade {$i}", 'type' => 'prestasi']);
    }
    kontenPost(['title' => 'Draf Rahasia', 'type' => 'berita']);
    foreach (range(1, 4) as $i) {
        kontenVideo(['title' => "Video Unggulan Uji {$i}", 'youtube_id' => str_repeat((string) $i, 11), 'is_featured' => true]);
    }
    kontenVideo(['title' => 'Video Biasa Uji', 'youtube_id' => 'BBBBBBBBBBB', 'is_featured' => false]);
    foreach (range(1, 5) as $i) {
        portalAlbumWithPhotos(1, ['title' => "Album Berfoto Uji {$i}"]);
    }
    kontenAlbum(['title' => 'Album Kosong Uji']);

    blkSet([
        blk('feed', ['source' => 'posts']),
        blk('feed', ['source' => 'achievements']),
        blk('feed', ['source' => 'videos']),
        blk('feed', ['source' => 'albums']),
    ]);

    $html = blkHome();
    $news = blkSection($html, __('pondok.portal.home.latest_news'));
    $achievements = blkSection($html, __('pondok.portal.home.latest_achievements'));
    $videos = blkSection($html, __('pondok.portal.home.featured_videos'));
    $albums = blkSection($html, __('pondok.portal.home.latest_albums'));
    $aria = fn (string $heading): string => 'aria-label="'.e(__('pondok.portal.home.see_all_label', ['section' => $heading])).'"';

    expect(preg_match_all('/Kabar harian \d/', $news))->toBe(6)->and($news)->toContain('Kabar harian 1')->not->toContain('Kabar harian 7')->not->toContain('Juara Olimpiade')->not->toContain('Draf Rahasia')
        ->and($news)->toContain('href="'.route('portal.posts.index').'"')->toContain($aria(__('pondok.portal.home.latest_news')))->toContain(__('pondok.portal.home.see_all'))
        ->and(preg_match_all('/Juara Olimpiade \d/', $achievements))->toBe(3)->and($achievements)->not->toContain('Kabar harian')->toContain(route('portal.posts.index', ['jenis' => 'prestasi']))
        ->and(substr_count($videos, 'embed/'))->toBe(3)->and($videos)->not->toContain('BBBBBBBBBBB')->toContain('href="'.route('portal.videos.index').'"')
        ->and(preg_match_all('/Album Berfoto Uji \d/', $albums))->toBe(4)->and($albums)->not->toContain('Album Kosong Uji')
        ->and($albums)->toContain(__('pondok.portal.home.see_all'))->toContain('href="'.route('portal.albums.index').'"');
});

it('AC-11: the adaptive grid uses as many large screen columns as there are items, up to the maximum', function () {
    $render = fn (int $count, int $max): string => Blade::render('<x-portal.adaptive-grid :count="$count" :max="$max">@for ($i = 0; $i < $count; $i++)<div>item</div>@endfor</x-portal.adaptive-grid>', ['count' => $count, 'max' => $max]);

    // [jumlah item, maks. kolom, kolom lg yang diharapkan]
    foreach ([[2, 4, 2], [3, 3, 3], [4, 4, 4], [5, 4, 4], [6, 3, 3], [8, 4, 4]] as [$count, $max, $columns]) {
        $html = $render($count, $max);
        preg_match_all('/lg:grid-cols-(\d)/', $html, $found);

        expect(array_unique($found[1]))->toBe([(string) $columns], "{$count} item, maks. {$max}: kolom lg ".json_encode($found[1]));
        expect(substr_count($html, '<div>item</div>'))->toBe($count);
    }

    $single = $render(1, 4);

    expect($single)->toContain('item')->not->toMatch('/(?:sm|md|lg):grid-cols-[2-4]/');
});

it('AC-5: never renders active markup from dangerous values written straight to settings', function () {
    $img = '<img src=x onerror=alert(1)>';
    blkSet([
        blk('hero', ['eyebrow' => $img, 'title' => $img, 'text' => $img, 'primary' => ['type' => 'url', 'label' => 'Bahaya satu', 'url' => 'javascript:alert(1)'], 'secondary' => ['type' => 'url', 'label' => 'Bahaya dua', 'url' => '//evil.test/x']]),
        blk('text_image', ['heading' => $img, 'text' => '<p onclick="x()">Halo <script>alert(1)</script><a href="javascript:alert(1)">satu</a> <a href="//evil.test">dua</a> <a href="https://luar.test/a">tiga</a> <a href=" JavaScript:alert(2)">empat</a></p><h1>Besar</h1>', 'button' => ['type' => 'url', 'label' => 'Bahaya tiga', 'url' => 'data:text/html,x']]),
        blk('features', ['heading' => $img, 'items' => [['title' => $img, 'text' => $img]]]),
        blk('cta', ['eyebrow' => $img, 'heading' => $img, 'text' => $img, 'primary' => ['type' => 'url', 'label' => 'Bahaya empat', 'url' => '/\\evil.test'], 'variant' => 'light']),
        blk('quote', ['text' => $img, 'translation' => $img, 'source' => $img]),
    ]);

    $html = blkHome();
    $text = blkSection($html, 'Halo');

    expect($html)->not->toContain('<img src=x')->not->toMatch('/<[a-z][^>]*\sonerror=/i')->not->toContain('<script>alert(1)')
        ->not->toContain('href="javascript')->not->toContain('href="//evil')->not->toContain('href="data:')->not->toContain('evil.test/x')->not->toContain('/\\evil.test')
        ->not->toContain('Bahaya satu')->not->toContain('Bahaya dua')->not->toContain('Bahaya tiga')->not->toContain('Bahaya empat')
        ->not->toContain('onclick')->not->toContain('<h1>Besar')->toContain('&lt;img src=x onerror=alert(1)&gt;')
        ->and($text)->toContain('Halo')->toContain('href="https://luar.test/a"')->toContain('rel="noopener noreferrer"')
        ->and(substr_count($html, '<h1'))->toBe(1);
});

it('AC-12: adding static blocks with page buttons does not add queries to the home page', function () {
    $pages = collect(range(1, 3))->map(fn (int $i) => staticPage(['slug' => "halaman-{$i}"]));
    $base = [
        blk('hero', ['title' => 'Judul hero uji', 'primary' => navItem('posts'), 'secondary' => navPage($pages[0])]),
        blk('text_image', ['heading' => 'Satu', 'text' => '<p>Isi</p>', 'button' => navPage($pages[0])]),
        blk('features', ['items' => [['title' => 'A']]]),
        blk('cta', ['heading' => 'Ajak satu', 'variant' => 'light', 'primary' => navPage($pages[0])]),
    ];
    $more = [
        ...$base,
        blk('text_image', ['heading' => 'Dua', 'text' => '<p>Isi</p>', 'button' => navPage($pages[1])]),
        blk('features', ['items' => [['title' => 'B']]]),
        blk('quote', ['text' => 'Kutipan uji']),
        blk('cta', ['heading' => 'Ajak dua', 'variant' => 'navy', 'primary' => navPage($pages[1])]),
        blk('cta', ['heading' => 'Ajak tiga', 'variant' => 'gold', 'primary' => navPage($pages[2]), 'secondary' => navItem('albums')]),
    ];
    $count = function (array $blocks): int {
        blkSet($blocks);
        DB::enableQueryLog();
        DB::flushQueryLog();
        blkHome();
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    };

    expect($count($more))->toBe($count($base));
});

it('AC-7: the video block shows the video with its heading even when the psb module is off', function () {
    Setting::set('modules.psb.enabled', false);
    $video = kontenVideo(['title' => 'Video Profil Uji', 'youtube_id' => 'AAAAAAAAAAA']);
    blkSet([blk('video', ['heading' => 'Kenali Kami', 'video_id' => $video->id])]);

    $html = blkHome();

    expect($html)->toContain('youtube-nocookie.com/embed/AAAAAAAAAAA')->toMatch('#<h2\b[^>]*>\s*Kenali Kami\s*</h2>#');
});
