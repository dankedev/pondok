<?php

use App\Actions\Konten\ThemeComponents;
use App\Models\AdmissionRequirement;
use App\Models\Album;
use App\Models\Post;
use App\Models\Setting;
use App\Models\StaticPage;
use App\Models\Video;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| F106 (kait tema untuk pil, slider, panel menu, kartu, dan halaman dalam). Semua kait hanya tercetak pada tema ber-CSS; pengecekan bagian
| memeriksa DOM di dalam akar `data-pt="<komponen>"`, bukan pencarian string global.
|
| Nama yang diasumsikan test (lihat docs/plans/F106-plan.md):
|   hero      : badge, content, hgroup, slider, slide
|   nav_menu  : panel, group, group-label
|   card      : varian post|album|video; bagian media, meta, badge, date, title, summary
|   page      : tanpa varian; bagian page-title, breadcrumb, filters, pagination, prose, figure, section
|   button    : varian = `$variant` komponen `x-ui.button` (primary, secondary, inverse, inverse-secondary)
|
| AC-6 membandingkan HTML tema TANPA CSS dengan fixture `tests/Fixtures/portal-html/*.html` yang direkam dari kode SEBELUM F106.
| Rekam ulang hanya bila tampilan memang diubah sengaja: `UPDATE_PORTAL_HTML=1 php artisan test --compact tests/Feature/Http/Portal/ThemeMarkupHooksTest.php --filter=AC-6`.
*/

beforeEach(function () {
    portalBoot();
    themeSandbox();
    themeInstalledSandbox();
});

const HK_FIXTURE_DIR = __DIR__.'/../../../Fixtures/portal-html';

/**
 * Data deterministik: 10 berita (satu berkategori + bersampul + berringkasan), album berkategori dan tanpa kategori, dua video (satu tanpa deskripsi),
 * gelombang PSB terbuka, syarat dan biaya, halaman statis, menu dengan grup, dan portal wali aktif (pilihan Masuk).
 *
 * @return object{post: Post, plainPost: Post, album: Album, plainAlbum: Album, bigAlbum: Album}
 */
function hkWorld(): object
{
    $f = portalBoot();
    Setting::set('modules.portal-wali.enabled', true);
    $wave = portalPsbOpen();
    Setting::set('modules.keuangan.enabled', true);
    keuanganRate(psbFeeType('saat_daftar', ['name' => 'Formulir Pendaftaran']), $wave->academicYear, $f->sd, 150000);
    AdmissionRequirement::factory()->create(['unit_id' => $f->sd->id, 'name' => 'Akta Kelahiran', 'sort_order' => 1]);

    $category = kontenCategory('post', 'Pengumuman');
    Storage::disk('public')->put('posts/covers/sampul.jpg', 'gambar');
    $post = portalPost(['title' => 'Berita berkategori', 'category_id' => $category->id, 'cover_path' => 'posts/covers/sampul.jpg', 'excerpt' => 'Ringkasan berita berkategori.']);
    $plainPost = portalPost(['title' => 'Berita polos']);

    for ($i = 1; $i <= 9; $i++) {
        portalPost(['title' => "Berita tambahan {$i}", 'published_at' => now()->subDays($i + 1)]);
    }

    $album = kontenAlbum(['title' => 'Album Ramadan']);
    $plainAlbum = kontenAlbum(['title' => 'Album kedua']);
    $bigAlbum = kontenAlbum(['title' => 'Album besar']);

    foreach ([$album, $plainAlbum] as $each) {
        kontenPhoto($each, ['path' => "albums/{$each->id}/foto-1.jpg", 'sort_order' => 1, 'caption' => "Foto pertama {$each->title}"]);
        kontenPhoto($each, ['path' => "albums/{$each->id}/foto-2.jpg", 'sort_order' => 2]);
    }

    for ($i = 1; $i <= 25; $i++) {
        kontenPhoto($bigAlbum, ['path' => "albums/{$bigAlbum->id}/foto-{$i}.jpg", 'sort_order' => $i]);
    }

    kontenVideo(['title' => 'Profil Pondok', 'description' => 'Deskripsi video profil.', 'youtube_id' => 'dQw4w9WgXcQ']);
    kontenVideo(['title' => 'Video tanpa deskripsi', 'youtube_id' => 'aqz-KE-bpKQ']);

    StaticPage::query()->where('slug', 'profil')->update(['body' => '<p>Isi halaman profil.</p>']);
    psbInfoPage(['body' => '<p>Syarat umum pendaftaran.</p>', 'status' => 'terbit']);
    navSet([navItem('home', 'Beranda'), navGroup('Profil', [navItem('posts', 'Berita'), navItem('albums', 'Galeri')]), navItem('videos', 'Video')], navItem('psb.register', 'Daftar'));

    return (object) ['post' => $post, 'plainPost' => $plainPost, 'album' => $album, 'plainAlbum' => $plainAlbum, 'bigAlbum' => $bigAlbum];
}

/**
 * Manifest tema uji: header (brand + nav_menu), footer, dan beranda dengan satu hero, satu feed_list, text_image, video, dan cta.
 *
 * @return array<string, mixed>
 */
function hkManifest(string $hero = 'center', string $feed = 'cards', string $cta = 'band'): array
{
    $top = ['style' => ['tone' => 'auto']];

    return [
        'header' => ['component' => 'container', 'children' => [['component' => 'brand'], ['component' => 'nav_menu']]],
        'footer' => ['component' => 'container', 'children' => [['component' => 'footer_widgets'], ['component' => 'copyright']]],
        'home' => ['layout' => [
            ['component' => 'hero', 'variant' => $hero, ...$top],
            ['component' => 'feed_list', 'variant' => $feed, ...$top],
            ['component' => 'text_image', ...$top],
            ['component' => 'video', ...$top],
            ['component' => 'cta', 'variant' => $cta, ...$top],
        ]],
    ];
}

/**
 * Aktifkan tema uji (ber-CSS atau tidak) dan bersihkan cache per request.
 *
 * @param  array<string, mixed>  $manifest
 */
function hkActivate(bool $css, array $manifest): string
{
    if ($css) {
        $pkg = themePkg(['manifest' => $manifest]);
        themePkgPlace($pkg);
        $slug = $pkg['slug'];
    } else {
        $slug = themeUniqueSlug();
        themeInstalledWrite($slug, [...themeBaseManifest(), ...$manifest]);
    }

    Setting::set('theme.template', $slug);
    app()->forgetScopedInstances();

    return $slug;
}

/**
 * Blok beranda: hero (gambar atau album, dengan tombol utama dan kedua), feed `$source`, text_image dengan tombol, video, cta.
 */
function hkBlocks(string $source = 'posts', ?Album $heroAlbum = null): void
{
    $video = Video::query()->where('title', 'Profil Pondok')->firstOrFail();
    $hero = ['eyebrow' => 'Label hero', 'title' => 'Judul hero', 'text' => 'Teks hero', 'primary' => navItem('psb.register', 'Daftar sekarang'), 'secondary' => navItem('posts', 'Baca berita')];

    blkSet([
        blk('hero', $heroAlbum ? [...$hero, 'album_id' => $heroAlbum->id] : [...$hero, 'image' => 'portal/blocks/hero.jpg', 'image_alt' => 'Gedung utama']),
        blk('feed', ['source' => $source, 'heading' => 'Daftar otomatis']),
        blk('text_image', ['heading' => 'Tentang', 'text' => '<p>Isi tentang.</p>', 'image' => 'portal/blocks/tentang.jpg', 'image_alt' => 'Foto', 'button' => navItem('posts', 'Selengkapnya')]),
        blk('video', ['heading' => 'Video seksi', 'video_id' => $video->id]),
        blk('cta', ['heading' => 'Ajakan', 'variant' => 'light', 'primary' => navItem('posts', 'Berita'), 'secondary' => navItem('albums', 'Galeri')]),
    ]);
}

/**
 * HTML dinormalisasi (host, token acak, spasi) satu tag per baris.
 */
function hkNormalize(string $html): string
{
    $html = str_replace(rtrim(url('/'), '/'), 'http://app.test', $html);
    $html = preg_replace('#/build/[^"\']*#', '/build/ASET', (string) $html);
    $html = preg_replace('/(nonce|csrf-token|_token)="[^"]*"/', '$1="X"', (string) $html);
    $html = preg_replace('/\s+/', ' ', (string) $html);
    $html = preg_replace('/>\s+</', '><', (string) $html);

    return trim((string) preg_replace('/>(?=<)/', ">\n", (string) $html))."\n";
}

/**
 * Buang semua jejak kait tema ber-CSS: atribut `data-pt*`, `<link>` tema, dan atribut `data-pt-theme` pada body.
 */
function hkStripThemeTraces(string $html): string
{
    $html = preg_replace('/\s*<link\b[^>]*\/tema\/[^>]*>/i', '', $html);

    return (string) preg_replace('/ data-pt(?:-[a-z]+)*="[^"]*"/', '', (string) $html);
}

function hkDoc(string $html): DOMXPath
{
    $doc = new DOMDocument;
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
    libxml_clear_errors();

    return new DOMXPath($doc);
}

/**
 * Akar `data-pt="<komponen>"` (opsional dengan varian atau elemen tertentu).
 *
 * @return list<DOMElement>
 */
function hkRoots(string $html, string $component, ?string $variant = null, ?string $tag = null): array
{
    $query = '//'.($tag ?? '*').'[@data-pt="'.$component.'"]'.($variant === null ? '' : '[@data-pt-variant="'.$variant.'"]');

    return array_values(array_filter(iterator_to_array(hkDoc($html)->query($query)), fn ($node) => $node instanceof DOMElement));
}

/**
 * Elemen bagian di dalam (keturunan, bukan akar itu sendiri) satu akar.
 *
 * @return list<DOMElement>
 */
function hkParts(DOMElement $root, string $part): array
{
    $xp = new DOMXPath($root->ownerDocument);

    return array_values(array_filter(iterator_to_array($xp->query('.//*[@data-pt-part="'.$part.'"]', $root)), fn ($node) => $node instanceof DOMElement));
}

/**
 * Daftar nama tag bagian pada akar (urutan dokumen).
 *
 * @return list<string>
 */
function hkPartTags(DOMElement $root, string $part): array
{
    return array_map(fn (DOMElement $node) => $node->tagName, hkParts($root, $part));
}

function hkFirstRoot(string $html, string $component, ?string $variant = null): DOMElement
{
    $roots = hkRoots($html, $component, $variant);

    expect($roots)->not->toBeEmpty("akar data-pt=\"{$component}\"".($variant ? " varian {$variant}" : '').' tidak ada');

    return $roots[0];
}

/**
 * Akar ber-`data-pt="card"` pada HTML, per varian.
 *
 * @return list<DOMElement>
 */
function hkCards(string $html, string $variant): array
{
    return hkRoots($html, 'card', $variant);
}

// ---------------------------------------------------------------------------------------------------------------------
// AC-1 hero
// ---------------------------------------------------------------------------------------------------------------------

it('AC-1: marks the hero pill, content wrapper and hgroup in both variants', function (string $variant, string $contentTag) {
    hkWorld();
    hkBlocks();
    hkActivate(true, hkManifest($variant));

    $hero = hkFirstRoot(blkHome(), 'hero', $variant);

    expect(hkPartTags($hero, 'badge'))->toBe(['span'], "{$variant}: pil PSB")
        ->and(hkPartTags($hero, 'content'))->toBe([$contentTag], "{$variant}: pembungkus teks")
        ->and(hkPartTags($hero, 'hgroup'))->toBe(['hgroup'])
        ->and(hkPartTags($hero, 'slider'))->toBeEmpty()
        ->and(hkPartTags($hero, 'slide'))->toBe(['img'], 'gambar tunggal = satu slide tanpa slider');

    $content = hkParts($hero, 'content')[0];

    foreach (['eyebrow', 'title', 'text', 'actions'] as $part) {
        expect(hkParts($content, $part))->not->toBeEmpty("{$variant}: {$part} berada di dalam content");
    }
})->with([
    'center' => ['center', 'div'],
    'split' => ['split', 'div'],
]);

it('AC-1: omits the hero badge when no wave is open', function () {
    hkWorld();
    Setting::set('modules.psb.enabled', false);
    hkBlocks();
    hkActivate(true, hkManifest('center'));

    $hero = hkFirstRoot(blkHome(), 'hero');

    expect(hkParts($hero, 'badge'))->toBeEmpty()
        ->and(hkParts($hero, 'content'))->not->toBeEmpty();
});

it('AC-1: marks the album slider and one slide per photo, in both variants', function (string $variant) {
    $w = hkWorld();
    hkBlocks('posts', $w->album);
    hkActivate(true, hkManifest($variant));

    $hero = hkFirstRoot(blkHome(), 'hero', $variant);
    $media = hkParts($hero, 'media')[0];

    expect(hkPartTags($hero, 'slider'))->toBe(['div'])
        ->and(hkParts($hero, 'slider')[0]->getAttribute('role'))->toBe('region')
        ->and(hkPartTags($hero, 'slide'))->toBe(['img', 'img'])
        ->and(hkParts($media, 'slider'))->not->toBeEmpty('slider berada di dalam media');
})->with(['center', 'split']);

// ---------------------------------------------------------------------------------------------------------------------
// AC-2 nav
// ---------------------------------------------------------------------------------------------------------------------

it('AC-2: marks panel and group on the desktop submenu and Masuk, label and group on the mobile groups', function () {
    hkWorld();
    hkActivate(true, hkManifest());
    $html = blkHome();
    $desktop = hkRoots($html, 'nav_menu', tag: 'nav')[0];
    $mobile = hkRoots($html, 'nav_menu', tag: 'details')[0];

    expect($desktop->getAttribute('data-pt-part'))->toBe('desktop')
        ->and($mobile->getAttribute('data-pt-part'))->toBe('mobile');

    // Desktop: submenu "Profil" dan "Masuk" (portal wali aktif) masing-masing satu group `details` dengan satu panel `div`.
    expect(hkPartTags($desktop, 'group'))->toBe(['details', 'details'])
        ->and(hkPartTags($desktop, 'panel'))->toBe(['div', 'div'])
        ->and(hkParts($desktop, 'group-label'))->toBeEmpty('desktop memakai summary, tanpa group-label');

    foreach (hkParts($desktop, 'group') as $group) {
        expect(hkPartTags($group, 'panel'))->toBe(['div'], 'panel ada di dalam group')
            ->and(hkParts($group, 'link'))->not->toBeEmpty();
    }

    // Seluler: panel = nav di dalam details[mobile]; group = div pembungkus; group-label = p judul.
    expect(hkPartTags($mobile, 'panel'))->toBe(['nav'])
        ->and(hkPartTags($mobile, 'group'))->toBe(['div', 'div'])
        ->and(hkPartTags($mobile, 'group-label'))->toBe(['p', 'p'])
        ->and(array_map(fn (DOMElement $node) => trim($node->textContent), hkParts($mobile, 'group-label')))->toContain('Profil');

    foreach (hkParts($mobile, 'group') as $group) {
        expect(hkPartTags($group, 'group-label'))->toBe(['p'], 'judul grup berada di dalam group');
    }
});

it('AC-2: marks the panel without any group when the menu has no submenu and no Masuk choices', function () {
    hkWorld();
    Setting::set('modules.portal-wali.enabled', false);
    navSet([navItem('home', 'Beranda'), navItem('posts', 'Berita')], false);
    hkActivate(true, hkManifest());
    $html = blkHome();
    $desktop = hkRoots($html, 'nav_menu', tag: 'nav')[0];
    $mobile = hkRoots($html, 'nav_menu', tag: 'details')[0];

    expect(hkParts($desktop, 'group'))->toBeEmpty()->and(hkParts($desktop, 'panel'))->toBeEmpty()
        ->and(hkParts($mobile, 'group'))->toBeEmpty()->and(hkParts($mobile, 'group-label'))->toBeEmpty()
        ->and(hkPartTags($mobile, 'panel'))->toBe(['nav']);
});

// ---------------------------------------------------------------------------------------------------------------------
// AC-3 kartu
// ---------------------------------------------------------------------------------------------------------------------

it('AC-3: marks post cards on the home feed (cards and magazine) with all parts inside the card root', function (string $feed) {
    hkWorld();
    // Feed magazine hanya menjadikan item pertama (lead) sebagai kartu; buat berita berkategori menjadi yang terbaru.
    Post::query()->where('title', 'Berita berkategori')->update(['published_at' => now()->subHour()]);
    hkBlocks('posts');
    hkActivate(true, hkManifest('center', $feed));

    $cards = hkCards(blkHome(), 'post');
    $titled = array_values(array_filter($cards, fn (DOMElement $card) => hkParts($card, 'badge') !== []));

    expect($cards)->not->toBeEmpty()
        ->and($cards[0]->tagName)->toBe('article')
        ->and($titled)->not->toBeEmpty('satu berita berkategori');

    $card = $titled[0];

    expect(hkPartTags($card, 'media'))->toBe(['img'])
        ->and(hkPartTags($card, 'meta'))->toBe(['div'])
        ->and(hkPartTags($card, 'badge'))->toBe(['span'])
        ->and(hkPartTags($card, 'date'))->toBe(['time'])
        ->and(hkPartTags($card, 'title'))->toBe(['h3'])
        ->and(hkPartTags($card, 'summary'))->toBe(['p']);

    $meta = hkParts($card, 'meta')[0];

    expect(hkParts($meta, 'badge'))->not->toBeEmpty('badge dan date berada di dalam meta')
        ->and(hkParts($meta, 'date'))->not->toBeEmpty();
})->with(['cards', 'magazine']);

it('AC-3: gives a post without cover or category a media placeholder but no badge', function () {
    hkWorld();
    $plain = array_values(array_filter(hkCards((function () {
        hkBlocks('posts');
        hkActivate(true, hkManifest());

        return blkHome();
    })(), 'post'), fn (DOMElement $card) => str_contains($card->textContent, 'Berita polos')));

    expect($plain)->toHaveCount(1);

    $card = $plain[0];

    expect(hkPartTags($card, 'media'))->toBe(['div'], 'placeholder tetap punya media')
        ->and(hkParts($card, 'badge'))->toBeEmpty()
        ->and(hkPartTags($card, 'title'))->toBe(['h3'])
        ->and(hkPartTags($card, 'date'))->toBe(['time']);
});

it('AC-3: marks album cards on the home feed and /galeri; /galeri cards carry the category badge, home cards keep media and meta', function () {
    hkWorld();
    hkBlocks('albums');
    hkActivate(true, hkManifest('center', 'magazine'));
    $home = hkCards(blkHome(), 'album');
    $gallery = hkCards($this->get('/galeri')->assertOk()->getContent(), 'album');

    expect($home)->not->toBeEmpty()->and($gallery)->toHaveCount(3);

    foreach ([...$home, ...$gallery] as $card) {
        expect($card->tagName)->toBe('article')
            ->and(hkPartTags($card, 'media'))->toBe(['img'])
            ->and(hkPartTags($card, 'meta'))->toBe(['p'])
            ->and(hkPartTags($card, 'title'))->toBeIn([['h2'], ['h3']]);
    }

    foreach ($gallery as $card) {
        expect(hkPartTags($card, 'badge'))->toBe(['span'])
            ->and(hkParts(hkParts($card, 'meta')[0], 'badge'))->not->toBeEmpty('badge berada di dalam meta');
    }
});

it('AC-3: marks video cards in feed cards, feed magazine, and /video, with summary only when a description exists', function () {
    hkWorld();
    // Beranda hanya memuat video unggulan (GetPortalHomeData: is_featured = true).
    Video::query()->update(['is_featured' => true]);
    hkBlocks('videos');
    $found = [];

    foreach (['cards', 'magazine'] as $feed) {
        hkActivate(true, hkManifest('center', $feed));
        $roots = hkCards(blkHome(), 'video');

        expect($roots)->not->toBeEmpty("feed {$feed}: kartu video");

        foreach ($roots as $card) {
            expect($card->tagName)->toBe('div')
                ->and(hkParts($card, 'media'))->not->toBeEmpty()
                ->and(hkPartTags($card, 'title'))->toBe(['h3']);
        }
    }

    $list = hkCards($this->get('/video')->assertOk()->getContent(), 'video');

    expect($list)->toHaveCount(2);

    foreach ($list as $card) {
        $found[str_contains($card->textContent, 'Deskripsi video profil.') ? 'with' : 'without'] = $card;

        expect($card->tagName)->toBe('article')
            ->and(hkParts($card, 'media'))->not->toBeEmpty()
            ->and(hkPartTags($card, 'title'))->toBe(['h2']);
    }

    expect(hkPartTags($found['with'], 'summary'))->toBe(['p'])
        ->and(hkParts($found['without'], 'summary'))->toBeEmpty();
});

it('AC-3: marks cards on /berita with h2 titles', function () {
    hkWorld();
    hkActivate(true, hkManifest());

    $cards = hkCards($this->get('/berita')->assertOk()->getContent(), 'post');

    expect($cards)->toHaveCount(9);

    foreach ($cards as $card) {
        expect(hkPartTags($card, 'title'))->toBe(['h2'])->and(hkParts($card, 'media'))->toHaveCount(1);
    }
});

it('AC-3: gives the news detail article the page hook and not the card hook', function () {
    $w = hkWorld();
    hkActivate(true, hkManifest());
    $html = $this->get(route('portal.posts.show', $w->post->slug))->assertOk()->getContent();
    $page = hkFirstRoot($html, 'page');

    expect($page->tagName)->toBe('article')
        ->and(hkRoots($html, 'card'))->toBeEmpty('detail berita bukan kartu')
        ->and(hkRoots($html, 'page'))->toHaveCount(1);
});

// ---------------------------------------------------------------------------------------------------------------------
// AC-4 halaman dalam
// ---------------------------------------------------------------------------------------------------------------------

/**
 * @return array<string, array{0: string, 1: list<string>, 2: list<string>}> halaman => [kunci, bagian wajib, bagian yang tidak boleh ada]
 */
function hkPageExpectations(): array
{
    return [
        'posts/index' => ['posts-index', ['page-title', 'breadcrumb', 'filters', 'pagination'], ['prose', 'figure', 'section']],
        'posts/show' => ['posts-show', ['page-title', 'breadcrumb', 'prose'], ['filters', 'pagination', 'figure', 'section']],
        'albums/index' => ['albums-index', ['page-title', 'breadcrumb', 'filters'], ['prose', 'figure', 'section', 'pagination']],
        'albums/show' => ['albums-show', ['page-title', 'breadcrumb', 'figure', 'pagination'], ['prose', 'section', 'filters']],
        'videos/index' => ['videos-index', ['page-title'], ['breadcrumb', 'prose', 'figure', 'section', 'filters', 'pagination']],
        'pages/show' => ['pages-show', ['page-title', 'breadcrumb', 'prose'], ['filters', 'pagination', 'figure', 'section']],
        'psb/info' => ['psb-info', ['page-title', 'breadcrumb', 'prose', 'section'], ['filters', 'pagination', 'figure']],
    ];
}

function hkPageUrl(string $key, object $w): string
{
    return match ($key) {
        'posts-index' => '/berita',
        'posts-show' => route('portal.posts.show', $w->post->slug, false),
        'albums-index' => '/galeri',
        'albums-show' => route('portal.albums.show', $w->bigAlbum->slug, false),
        'videos-index' => '/video',
        'pages-show' => '/profil',
        'psb-info' => '/info-psb',
    };
}

it('AC-4: gives each inner page one page root with a single page-title h1 and exactly its optional parts', function (string $name) {
    [$key, $present, $absent] = hkPageExpectations()[$name];
    $w = hkWorld();
    hkActivate(true, hkManifest());
    $html = $this->get(hkPageUrl($key, $w))->assertOk()->getContent();
    $roots = hkRoots($html, 'page');

    expect($roots)->toHaveCount(1, "{$name}: satu akar page");

    $page = $roots[0];

    expect(hkPartTags($page, 'page-title'))->toBe(['h1']);

    foreach ($present as $part) {
        expect(hkParts($page, $part))->not->toBeEmpty("{$name}: {$part} di dalam akar page");
    }

    foreach ($absent as $part) {
        expect(hkParts($page, $part))->toBeEmpty("{$name}: {$part} tidak boleh ada");
        expect(hkQueryOutsideAny($html, $part))->toBeEmpty("{$name}: {$part} tidak boleh bocor di luar page");
    }
})->with(array_map(fn (string $name) => $name, array_keys(hkPageExpectations())));

/**
 * Elemen bagian halaman yang berada di luar akar page (mestinya kosong untuk bagian halaman).
 *
 * @return list<string>
 */
function hkQueryOutsideAny(string $html, string $part): array
{
    $pageParts = ['page-title', 'breadcrumb', 'filters', 'pagination', 'prose', 'figure', 'section'];

    if (! in_array($part, $pageParts, true)) {
        return [];
    }

    $nodes = hkDoc($html)->query('//*[@data-pt-part="'.$part.'"][not(ancestor::*[@data-pt="page"]) and not(@data-pt)]');

    return array_map(fn (DOMNode $node) => $node->nodeName, iterator_to_array($nodes));
}

it('AC-4: marks the breadcrumb as a nav and the filters as navs, and keeps page-title distinct from the card title', function () {
    $w = hkWorld();
    hkActivate(true, hkManifest());
    $html = $this->get('/berita')->assertOk()->getContent();
    $page = hkFirstRoot($html, 'page');

    expect(hkPartTags($page, 'breadcrumb'))->toBe(['nav'])
        ->and(hkPartTags($page, 'filters'))->toContain('nav')
        ->and(hkPartTags($page, 'pagination'))->toBe(['nav']);

    // Judul kartu bersarang tidak memakai nama `page-title`.
    foreach (hkCards($html, 'post') as $card) {
        expect(hkParts($card, 'page-title'))->toBeEmpty();
    }

    expect(hkParts(hkFirstRoot($this->get(hkPageUrl('albums-show', $w))->assertOk()->getContent(), 'page'), 'figure'))->toHaveCount(24);
});

it('AC-4: prints the pagination hook only when there is more than one page', function () {
    hkWorld();
    hkActivate(true, hkManifest());

    $many = $this->get('/berita')->assertOk()->getContent();
    $single = $this->get('/berita?kategori=pengumuman')->assertOk()->getContent();

    expect(hkParts(hkFirstRoot($many, 'page'), 'pagination'))->toHaveCount(1)
        ->and(hkParts(hkFirstRoot($single, 'page'), 'pagination'))->toBeEmpty()
        ->and(hkQueryOutsideAny($single, 'pagination'))->toBeEmpty();
});

it('AC-4: marks every section of the PSB info page (waves, requirements, fees)', function () {
    hkWorld();
    hkActivate(true, hkManifest());
    $page = hkFirstRoot($this->get('/info-psb')->assertOk()->getContent(), 'page');

    expect(hkPartTags($page, 'section'))->toBe(['section', 'section', 'section'], 'gelombang, syarat, biaya')
        ->and(hkPartTags($page, 'prose'))->toBe(['div']);
});

// ---------------------------------------------------------------------------------------------------------------------
// AC-5 tombol
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Pasangan [teks, varian] dari semua akar `data-pt="button"`.
 *
 * @return array<string, string> teks => varian
 */
function hkButtons(string $html): array
{
    $result = [];

    foreach (hkRoots($html, 'button') as $button) {
        $result[trim(preg_replace('/\s+/', ' ', $button->textContent))] = $button->getAttribute('data-pt-variant');
    }

    return $result;
}

it('AC-5: marks the hero and band-cta buttons with variant and no data-pt-part', function () {
    hkWorld();
    hkBlocks();
    hkActivate(true, hkManifest('center', 'cards', 'band'));
    $html = blkHome();
    $buttons = hkButtons($html);

    expect($buttons)->toHaveKey('Daftar sekarang')->toHaveKey('Baca berita')
        ->and($buttons['Daftar sekarang'])->toBe('primary')
        ->and($buttons['Baca berita'])->toBe('secondary')
        ->and($buttons['Berita'])->toBeIn(['inverse', 'primary'])
        ->and($buttons['Galeri'])->toBeIn(['inverse-secondary', 'secondary']);

    foreach (hkRoots($html, 'button') as $button) {
        expect(in_array($button->tagName, ['a', 'button'], true))->toBeTrue()
            ->and($button->getAttribute('data-pt-variant'))->toBeIn(['primary', 'secondary', 'inverse', 'inverse-secondary']);
    }
});

it('AC-5: marks the split hero, card cta and text_image buttons and keeps inverse variants on a dark cta', function () {
    hkWorld();
    hkBlocks();
    hkActivate(true, hkManifest('split', 'cards', 'card'));
    $html = blkHome();
    $buttons = hkButtons($html);

    expect($buttons)->toHaveKeys(['Daftar sekarang', 'Baca berita', 'Berita', 'Galeri']);

    $hero = hkFirstRoot($html, 'hero', 'split');

    expect(array_values(array_filter(hkRoots($html, 'button'), fn (DOMElement $button) => str_contains($button->textContent, 'Daftar sekarang'))))->toHaveCount(1)
        ->and(hkParts($hero, 'actions'))->not->toBeEmpty();
});

it('AC-5: marks the "Daftar sekarang" button on the PSB info page with the primary variant', function () {
    hkWorld();
    hkActivate(true, hkManifest());
    $buttons = hkButtons($this->get('/info-psb')->assertOk()->getContent());

    expect($buttons)->toHaveCount(1)->and(array_values($buttons))->toBe(['primary']);
});

it('AC-5: marks the buttons of the bundled default and centered blocks presets', function (string $preset) {
    hkWorld();
    hkBlocks();
    hkActivate(true, [...hkManifest(), 'home' => ['layout' => [['component' => 'blocks', 'variant' => $preset]]]]);
    $buttons = hkButtons(blkHome());

    expect($buttons)->toHaveKey('Daftar sekarang')->toHaveKey('Baca berita');
    expect($buttons['Daftar sekarang'])->toBe('primary');
})->with(['default', 'centered']);

it('AC-5: prints no button hook on a theme without css', function () {
    hkWorld();
    hkBlocks();
    hkActivate(false, hkManifest());

    expect(blkHome())->not->toContain('data-pt');
    expect($this->get('/info-psb')->assertOk()->getContent())->not->toContain('data-pt');
});

// ---------------------------------------------------------------------------------------------------------------------
// AC-6 tema tanpa CSS tidak berubah (fixture direkam sebelum F106)
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Siapkan satu skenario (data deterministik `hkWorld`) lalu kembalikan penyaji `fn (bool $css): string` yang menghasilkan HTML ternormalisasi.
 *
 * @return Closure(bool): string
 */
function hkScenario(string $name): Closure
{
    $w = hkWorld();
    $pages = [
        'posts-index' => '/berita',
        'posts-show' => '/berita/'.$w->post->slug,
        'albums-index' => '/galeri',
        'albums-show' => '/galeri/'.$w->bigAlbum->slug,
        'videos-index' => '/video',
        'pages-show' => '/profil',
        'psb-info' => '/info-psb',
        'kontak-show' => '/kontak',
    ];
    $homes = [
        'beranda-center-posts-cards' => ['posts', null, ['center', 'cards', 'band']],
        'beranda-split-albums-magazine' => ['albums', $w->album, ['split', 'magazine', 'card']],
        'beranda-center-videos-cards' => ['videos', null, ['center', 'cards', 'band']],
    ];

    if ($name === 'kontak-show') {
        portalContactData();
        StaticPage::query()->where('slug', 'kontak')->update(['status' => 'terbit', 'body' => '<p>Hubungi kami.</p>']);
    }

    if (isset($pages[$name])) {
        return function (bool $css) use ($pages, $name): string {
            hkActivate($css, hkManifest());

            return hkNormalize(test()->get($pages[$name])->assertOk()->getContent());
        };
    }

    if ($name === 'beranda-blocks') {
        hkBlocks('posts');

        return function (bool $css): string {
            hkActivate($css, [...hkManifest(), 'home' => ['layout' => [['component' => 'blocks', 'variant' => 'default']]]]);

            return hkNormalize(blkHome());
        };
    }

    [$source, $album, $variants] = $homes[$name];
    hkBlocks($source, $album);

    return function (bool $css) use ($variants): string {
        hkActivate($css, hkManifest(...$variants));

        return hkNormalize(blkHome());
    };
}

/**
 * @return list<string>
 */
function hkSnapshotNames(): array
{
    return ['beranda-center-posts-cards', 'beranda-split-albums-magazine', 'beranda-center-videos-cards', 'beranda-blocks', 'posts-index', 'posts-show', 'albums-index', 'albums-show', 'videos-index', 'pages-show', 'psb-info', 'kontak-show'];
}

function hkSnapshotDataset(array $names): Closure
{
    return fn () => array_combine($names, array_map(fn (string $name) => [$name], $names));
}

it('AC-6: renders a theme without css exactly as the HTML recorded before F106', function (string $name) {
    $html = hkScenario($name)(false);
    $path = HK_FIXTURE_DIR."/{$name}.html";

    if (getenv('UPDATE_PORTAL_HTML') === '1') {
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $html);
    }

    expect(is_file($path))->toBeTrue("Fixture {$name} belum direkam")
        ->and($html)->not->toContain('data-pt')
        ->and($html)->not->toMatch('/<[a-z][a-z0-9-]*\s+>/i', 'spasi sisa di dalam tag')
        ->and($html)->toBe((string) file_get_contents($path), "Halaman {$name} berubah untuk tema tanpa CSS");
})->with(hkSnapshotDataset(hkSnapshotNames()));

it('AC-6: matches the css-less HTML once data-pt attributes, the theme link and data-pt-theme are stripped from the css theme', function (string $name) {
    $render = hkScenario($name);
    $plain = $render(false);
    $themed = $render(true);

    expect($themed)->toContain('data-pt=')
        ->and(preg_replace('/\s+/', ' ', hkStripThemeTraces($themed)))->toBe(preg_replace('/\s+/', ' ', $plain), "{$name}: ber-CSS setelah dibersihkan sama dengan tanpa CSS");
})->with(hkSnapshotDataset(['beranda-center-posts-cards', 'beranda-split-albums-magazine', 'beranda-blocks', 'posts-index', 'albums-show', 'psb-info', 'kontak-show']));

// ---------------------------------------------------------------------------------------------------------------------
// AC-7 kompatibilitas F105
// ---------------------------------------------------------------------------------------------------------------------

it('AC-7: keeps every F105 component-part pair in PARTS and prints it on a full-content css theme', function () {
    // Daftar beku dari F105 (docs/tema-kontrak-markup.md sebelum F106). Menambah nama boleh; mengubah atau menghapus tidak.
    $frozen = [
        'container' => ['inner'],
        'columns' => ['grid', 'cell'],
        'divider' => ['line', 'diamond'],
        'brand' => ['name'],
        'nav_menu' => ['desktop', 'mobile', 'toggle', 'link'],
        'footer_widgets' => ['widget', 'heading'],
        'hero' => ['eyebrow', 'title', 'text', 'actions', 'media'],
        'text_image' => ['media', 'heading', 'body', 'link'],
        'feature_grid' => ['heading', 'item', 'item-title', 'item-text', 'number'],
        'quote' => ['text', 'source'],
        'cta' => ['heading', 'actions'],
        'video' => ['heading', 'player'],
        'feed_list' => ['head', 'heading', 'see-all', 'list', 'entry', 'lead'],
        'blocks' => ['hero', 'text-image', 'features', 'feed', 'quote', 'video', 'cta'],
    ];

    foreach ($frozen as $component => $parts) {
        expect(ThemeComponents::PARTS)->toHaveKey($component);

        foreach ($parts as $part) {
            expect(in_array($part, ThemeComponents::PARTS[$component], true))->toBeTrue("F105 {$component}/{$part} hilang dari PARTS");
        }
    }
});

// ---------------------------------------------------------------------------------------------------------------------
// AC-8 dan AC-9 dokumen
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Baris tabel dokumen per komponen: komponen => teks baris.
 *
 * @return array<string, string>
 */
function hkDocRows(): array
{
    $rows = [];

    foreach (explode("\n", (string) file_get_contents(base_path('docs/tema-kontrak-markup.md'))) as $line) {
        if (preg_match('/^\|\s*`([a-z_]+)`\s*\|/', $line, $m) === 1) {
            $rows[$m[1]] = $line;
        }
    }

    return $rows;
}

it('AC-8: documents every component, variant and part of PARTS and SHARED_VARIANTS in a table row, and no unknown part', function () {
    $rows = hkDocRows();
    $variants = [...ThemeComponents::VARIANTS, ...(defined(ThemeComponents::class.'::SHARED_VARIANTS') ? ThemeComponents::SHARED_VARIANTS : [])];

    expect(defined(ThemeComponents::class.'::SHARED_VARIANTS'))->toBeTrue('ThemeComponents::SHARED_VARIANTS belum ada');

    foreach (ThemeComponents::PARTS as $component => $parts) {
        expect($rows)->toHaveKey($component);

        foreach ($parts as $part) {
            expect($rows[$component])->toContain("`{$part}`");
        }

        // Semua nama berkutip pada kolom Bagian harus ada di PARTS (opsional ditandai `opsional:` boleh di luar PARTS).
        $cells = array_map('trim', explode('|', $rows[$component]));
        preg_match_all('/`([a-z][a-z0-9-]*)`/', $cells[3] ?? '', $named);

        foreach ($named[1] as $name) {
            expect(in_array($name, $parts, true) || str_contains($cells[3], '*opsional:*'))->toBeTrue("{$component}: `{$name}` ada di dokumen tetapi bukan di PARTS");
        }
    }

    foreach ($variants as $component => $list) {
        foreach ($list as $variant) {
            expect($rows)->toHaveKey($component)
                ->and($rows[$component])->toContain("`{$variant}`");
        }
    }
});

it('AC-9: documents the hero order, selector specificity, nav button vs button component, desktop group-label and the feed_list cards note', function () {
    $doc = (string) file_get_contents(base_path('docs/tema-kontrak-markup.md'));

    expect($doc)->toContain('order-first')
        ->and($doc)->toContain('order:-1')
        ->and($doc)->toContain('[data-pt="card"] [data-pt-part="title"]')
        ->and($doc)->toContain('[data-pt="page"] [data-pt-part="page-title"]')
        ->and($doc)->toContain('x-ui.button')
        ->and($doc)->toContain('[data-pt-part="group"] > summary')
        ->and($doc)->not->toContain('tidak punya kait: tata lewat');
});

// ---------------------------------------------------------------------------------------------------------------------
// AC-10 halaman error tanpa database
// ---------------------------------------------------------------------------------------------------------------------

it('AC-10: renders errors.layout without any settings query while a css theme is active', function () {
    hkWorld();
    hkActivate(true, hkManifest());
    $exception = new HttpException(500);
    $html = '';

    $queries = recordedQueries(function () use (&$html, $exception): void {
        $html = view('errors.layout', ['exception' => $exception])->render();
    });

    expect($html)->toContain('<a')->and($html)->not->toContain('data-pt')
        ->and(queriesOnTable($queries, 'settings'))->toBe(0);
});

it('AC-10: renders the 404 page without any data-pt hook while a css theme is active', function () {
    hkWorld();
    hkActivate(true, hkManifest());

    $html = $this->get('/halaman-yang-tidak-ada-sama-sekali/lagi')->assertNotFound()->getContent();

    expect($html)->not->toContain('data-pt="button"')->not->toContain('data-pt="hero"');
});

// ---------------------------------------------------------------------------------------------------------------------
// VERIFY adversarial
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Halaman dalam yang belum tercakup fixture: halaman ke-2, filter kategori, dan kombinasi menu atau modul.
 *
 * @return array<string, array{0: string, 1: string}> nama => [skenario, jalur]
 */
function hkVerifyPages(): array
{
    return [
        'berita halaman 2' => ['default', '/berita?page=2'],
        'berita filter kategori' => ['default', '/berita?kategori=pengumuman'],
        'album besar halaman 2' => ['bigalbum2', '/galeri/{bigAlbum}?page=2'],
        'beranda menu tanpa submenu' => ['flatmenu', '/'],
        'berita menu tanpa submenu' => ['flatmenu', '/berita'],
        'info psb modul psb mati' => ['psboff', '/profil'],
        'beranda portal wali mati' => ['walioff', '/'],
        'video tanpa video unggulan' => ['default', '/video'],
    ];
}

function hkVerifyPrepare(string $scenario): object
{
    $w = hkWorld();

    match ($scenario) {
        'flatmenu' => navSet([navItem('home', 'Beranda'), navItem('posts', 'Berita')], navItem('psb.register', 'Daftar')),
        'psboff' => Setting::set('modules.psb.enabled', false),
        'walioff' => Setting::set('modules.portal-wali.enabled', false),
        default => null,
    };

    return $w;
}

it('VERIFY: the css theme prints no duplicated, escaped or empty-spaced hook attribute on any inner page', function (string $name) {
    [$scenario, $path] = hkVerifyPages()[$name];
    $w = hkVerifyPrepare($scenario);
    hkActivate(true, hkManifest());
    $html = $this->get(str_replace('{bigAlbum}', $w->bigAlbum->slug, $path))->assertOk()->getContent();

    preg_match_all('/<[a-z][a-z0-9-]*\b[^>]*>/i', $html, $tags);

    foreach ($tags[0] as $tag) {
        foreach (['data-pt', 'data-pt-variant', 'data-pt-part'] as $attribute) {
            expect(substr_count($tag, " {$attribute}=\""))->toBeLessThanOrEqual(1, "{$name}: {$attribute} ganda pada {$tag}");
        }

        expect($tag)->not->toMatch('/\s{2,}>|\s>$|data-pt[a-z-]*=""/', "{$name}: spasi sisa atau nilai kosong pada {$tag}");
    }

    // Kait tidak pernah ikut tercetak sebagai teks atau di dalam nilai atribut lain.
    expect(preg_match('/&lt;[^&]*data-pt/', $html))->toBe(0)
        ->and(preg_match('/="[^"]*\sdata-pt[a-z-]*=\\\\?&quot;/', $html))->toBe(0);
})->with(array_keys(hkVerifyPages()));

it('VERIFY: once its hooks are stripped the css theme renders the same HTML as a theme without css', function (string $name) {
    [$scenario, $path] = hkVerifyPages()[$name];
    $w = hkVerifyPrepare($scenario);
    $path = str_replace('{bigAlbum}', $w->bigAlbum->slug, $path);

    hkActivate(false, hkManifest());
    $plain = hkNormalize($this->get($path)->assertOk()->getContent());
    hkActivate(true, hkManifest());
    $themed = hkNormalize($this->get($path)->assertOk()->getContent());

    expect($plain)->not->toContain('data-pt')
        ->and(preg_replace('/\s+/', ' ', hkStripThemeTraces($themed)))->toBe(preg_replace('/\s+/', ' ', $plain), $name);
})->with(array_keys(hkVerifyPages()));

it('VERIFY: nested card parts never leak out of their card and every inner page has exactly one h1', function (string $path) {
    hkWorld();
    hkActivate(true, hkManifest());
    $xpath = hkDoc($this->get($path)->assertOk()->getContent());

    $stray = $xpath->query('//*[@data-pt-part="title" or @data-pt-part="meta" or @data-pt-part="summary" or @data-pt-part="date"][not(ancestor::*[@data-pt="card"])]');
    $h1 = $xpath->query('//h1');
    $pageTitles = $xpath->query('//*[@data-pt-part="page-title"]');
    $cardInPage = $xpath->query('//*[@data-pt="page"]//*[@data-pt="card"]//*[@data-pt-part="page-title"]');

    expect($stray->length)->toBe(0, "{$path}: bagian kartu di luar kartu")
        ->and($h1->length)->toBe(1)
        ->and($pageTitles->length)->toBe(1)
        ->and($cardInPage->length)->toBe(0);
})->with(['/berita', '/galeri', '/video']);

it('VERIFY: portal wali pages render unchanged apart from hook attributes while a css theme is active', function () {
    $f = waliBoot();
    waliInvoice($f, $f->s1);
    Setting::set('theme.template', null);
    $paths = ['/wali', route('portal-wali.students.show', $f->s1, false)];
    $plain = [];

    hkActivate(false, hkManifest());

    foreach ($paths as $path) {
        $plain[$path] = hkNormalize($this->actingAs($f->wali)->get($path)->assertOk()->getContent());
    }

    hkActivate(true, hkManifest());

    foreach ($paths as $path) {
        $themed = hkNormalize($this->actingAs($f->wali)->get($path)->assertOk()->getContent());

        expect(preg_replace('/\s+/', ' ', hkStripThemeTraces($themed)))->toBe(preg_replace('/\s+/', ' ', $plain[$path]), $path);
    }
});

// ---------------------------------------------------------------------------------------------------------------------
// F108 kait untuk elemen yang masih tanpa kait (docs/plans/F108-plan.md)
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Bagian yang menjadi anak langsung `$parent`.
 *
 * @return list<DOMElement>
 */
function hkDirect(DOMElement $parent, string $part): array
{
    return array_values(array_filter(hkParts($parent, $part), fn (DOMElement $node) => $node->parentNode === $parent));
}

/**
 * Jumlah elemen bagian di seluruh dokumen (untuk memastikan bagian halaman tidak bocor ke luar akar).
 */
function hkPartCount(string $html, string $part): int
{
    return hkDoc($html)->query('//*[@data-pt-part="'.$part.'"]')->length;
}

/**
 * Tiap kartu `$variant` punya satu `body` anak langsung akar berisi title dan meta (dan summary hanya bila `$summary`), tanpa media.
 *
 * @param  list<DOMElement>  $cards
 */
function hkExpectCardBodies(array $cards, bool $summary): void
{
    expect($cards)->not->toBeEmpty();

    foreach ($cards as $card) {
        $bodies = hkDirect($card, 'body');

        expect($bodies)->toHaveCount(1)
            ->and($bodies[0]->tagName)->toBe('div')
            ->and(hkParts($card, 'body'))->toHaveCount(1)
            ->and(hkParts($bodies[0], 'title'))->toHaveCount(1)
            ->and(hkParts($bodies[0], 'meta'))->toHaveCount(1)
            ->and(hkParts($bodies[0], 'media'))->toBeEmpty('media di luar body')
            ->and(count(hkParts($bodies[0], 'summary')))->toBe($summary ? 1 : 0);

        if (! $summary) {
            expect(hkParts($card, 'summary'))->toBeEmpty();
        }
    }
}

it('F108 AC-1: wraps title, meta and summary of post cards in a body part on the home feed and /berita', function (string $source) {
    hkWorld();
    hkBlocks('posts');
    hkActivate(true, hkManifest('center', $source));

    hkExpectCardBodies(hkCards(blkHome(), 'post'), true);
    hkExpectCardBodies(hkCards($this->get('/berita')->assertOk()->getContent(), 'post'), true);
})->with(['cards', 'magazine']);

it('F108 AC-1: wraps title and meta of album cards in a body part, without summary, on the home feed and /galeri', function () {
    hkWorld();
    hkBlocks('albums');
    hkActivate(true, hkManifest('center', 'cards'));

    hkExpectCardBodies(hkCards(blkHome(), 'album'), false);
    hkExpectCardBodies(hkCards($this->get('/galeri')->assertOk()->getContent(), 'album'), false);
});

it('F108 AC-1: gives video cards no body part', function () {
    hkWorld();
    Video::query()->update(['is_featured' => true]);
    hkBlocks('videos');
    hkActivate(true, hkManifest('center', 'cards'));

    foreach ([blkHome(), $this->get('/video')->assertOk()->getContent()] as $html) {
        $cards = hkCards($html, 'video');

        expect($cards)->not->toBeEmpty();

        foreach ($cards as $card) {
            expect(hkParts($card, 'body'))->toBeEmpty();
        }
    }
});

it('F108 AC-2: nests content inside inner under the text_image root, with media beside content when there is an image', function () {
    hkWorld();
    hkBlocks('posts');
    hkActivate(true, hkManifest());
    $root = hkFirstRoot(blkHome(), 'text_image');
    $inner = hkDirect($root, 'inner');

    expect($inner)->toHaveCount(1)
        ->and($inner[0]->tagName)->toBe('div');

    $content = hkDirect($inner[0], 'content');
    $media = hkDirect($inner[0], 'media');

    expect($content)->toHaveCount(1)
        ->and($content[0]->tagName)->toBe('div')
        ->and($media)->toHaveCount(1)
        ->and(hkParts($root, 'content'))->toHaveCount(1)
        ->and(hkParts($content[0], 'heading'))->toHaveCount(1)
        ->and(hkParts($content[0], 'body'))->toHaveCount(1)
        ->and(hkParts($content[0], 'link'))->toHaveCount(1);
});

it('F108 AC-2: keeps inner and content on a text_image without image', function () {
    hkWorld();
    blkSet([blk('text_image', ['heading' => 'Tentang', 'text' => '<p>Isi tentang.</p>'])]);
    hkActivate(true, hkManifest());
    $root = hkFirstRoot(blkHome(), 'text_image');
    $inner = hkDirect($root, 'inner');

    expect($inner)->toHaveCount(1)
        ->and(hkParts($root, 'media'))->toBeEmpty()
        ->and(hkDirect($inner[0], 'content'))->toHaveCount(1)
        ->and(hkParts($root, 'heading'))->toHaveCount(1);
});

it('F108 AC-3: puts one inner part directly under the cta root holding heading and actions', function (string $variant) {
    hkWorld();
    hkBlocks('posts');
    hkActivate(true, hkManifest('center', 'cards', $variant));
    $root = hkFirstRoot(blkHome(), 'cta', $variant);
    $inner = hkDirect($root, 'inner');

    expect($inner)->toHaveCount(1)
        ->and($inner[0]->tagName)->toBe('div')
        ->and(hkParts($root, 'inner'))->toHaveCount(1)
        ->and(hkParts($inner[0], 'heading'))->toHaveCount(1)
        ->and(hkParts($inner[0], 'actions'))->toHaveCount(1);
})->with(['band', 'card']);

it('F108 AC-4: marks the quote figure around the text, and the decorative mark only on the framed variant', function (string $variant, int $marks) {
    hkWorld();
    blkSet([blk('quote', ['text' => 'Kutipan uji.', 'source' => 'Sumber uji'])]);
    hkActivate(true, [...hkManifest(), 'home' => ['layout' => [['component' => 'quote', 'variant' => $variant, 'style' => ['tone' => 'auto']]]]]);
    $root = hkFirstRoot(blkHome(), 'quote', $variant);
    $figure = hkDirect($root, 'figure');

    expect($figure)->toHaveCount(1)
        ->and($figure[0]->tagName)->toBe('figure')
        ->and(hkParts($figure[0], 'text'))->toHaveCount(1)
        ->and(hkParts($root, 'mark'))->toHaveCount($marks);

    if ($marks === 1) {
        expect(hkParts($figure[0], 'mark')[0]->getAttribute('aria-hidden'))->toBe('true');
    }
})->with([['plain', 0], ['framed', 1]]);

it('F108 AC-5: marks the institution name in the compact footer (psb pages) and no logo', function () {
    hkWorld();
    Setting::set('school.logo_mobile_path', 'school/logo-uji.png');
    ftrSet([ftr('text', ['body' => '<p>Isi</p>', 'show_logo' => true])]);
    hkActivate(true, hkManifest());
    $root = hkFirstRoot($this->get('/info-psb')->assertOk()->getContent(), 'footer_widgets');
    $name = hkParts($root, 'name');

    expect($name)->toHaveCount(1)
        ->and($name[0]->tagName)->toBe('p')
        ->and(trim($name[0]->textContent))->toBe(Setting::get('school.name', trim($name[0]->textContent)))
        ->and(hkParts($root, 'logo'))->toBeEmpty();
});

it('F108 AC-5: marks name and logo of the Teks widget in the full footer when the logo exists', function () {
    hkWorld();
    Setting::set('school.logo_mobile_path', 'school/logo-uji.png');
    ftrSet([ftr('text', ['body' => '<p>Isi</p>', 'show_logo' => true])]);
    hkActivate(true, hkManifest());
    $root = hkFirstRoot($this->get('/berita')->assertOk()->getContent(), 'footer_widgets');
    $name = hkParts($root, 'name');
    $logo = hkParts($root, 'logo');

    expect($name)->toHaveCount(1)
        ->and($name[0]->tagName)->toBe('p')
        ->and($logo)->toHaveCount(1)
        ->and($logo[0]->tagName)->toBe('span')
        ->and($logo[0]->getElementsByTagName('img')->length)->toBe(1);
});

it('F108 AC-5: prints name but no logo for the Teks widget when there is no logo file', function () {
    hkWorld();
    Setting::set('school.logo_mobile_path', null);
    Setting::set('school.logo_desktop_path', null);
    ftrSet([ftr('text', ['body' => '<p>Isi</p>', 'show_logo' => true])]);
    hkActivate(true, hkManifest());
    $root = hkFirstRoot($this->get('/berita')->assertOk()->getContent(), 'footer_widgets');

    expect(hkParts($root, 'name'))->toHaveCount(1)
        ->and(hkParts($root, 'logo'))->toBeEmpty();
});

it('F108 AC-5: prints no data-pt in the footer of a theme without css, in compact and full mode', function (string $path) {
    hkWorld();
    Setting::set('school.logo_mobile_path', 'school/logo-uji.png');
    ftrSet([ftr('text', ['body' => '<p>Isi</p>', 'show_logo' => true])]);
    hkActivate(false, hkManifest());
    $html = $this->get($path)->assertOk()->getContent();

    expect(preg_match('#<footer\b.*?</footer>#s', $html, $footer))->toBe(1)
        ->and($footer[0])->not->toContain('data-pt')
        ->and($footer[0])->not->toMatch('/<[a-z][a-z0-9-]*\s+>/i');
})->with(['/info-psb', '/berita']);

it('F108 AC-6: marks meta, lead, cover and back on the news detail page inside the page root', function () {
    $w = hkWorld();
    hkActivate(true, hkManifest());
    $html = $this->get(route('portal.posts.show', $w->post->slug, false))->assertOk()->getContent();
    $page = hkFirstRoot($html, 'page');
    $meta = hkParts($page, 'meta');
    $lead = hkParts($page, 'lead');
    $cover = hkParts($page, 'cover');
    $back = hkParts($page, 'back');

    expect($meta)->toHaveCount(1)
        ->and($meta[0]->tagName)->toBe('div')
        ->and($meta[0]->getElementsByTagName('time')->length)->toBe(1)
        ->and($lead)->toHaveCount(1)
        ->and($lead[0]->tagName)->toBe('p')
        ->and(trim($lead[0]->textContent))->toBe('Ringkasan berita berkategori.')
        ->and($cover)->toHaveCount(1)
        ->and($cover[0]->tagName)->toBe('img')
        ->and($back)->toHaveCount(1)
        ->and($back[0]->tagName)->toBe('a');

    foreach (['meta', 'lead', 'cover', 'back'] as $part) {
        expect(hkPartCount($html, $part))->toBe(1, "{$part} tidak boleh bocor ke luar page");
    }
});

it('F108 AC-6: omits lead and cover on a news detail page without excerpt and cover, keeping meta and back', function () {
    $w = hkWorld();
    hkActivate(true, hkManifest());
    $page = hkFirstRoot($this->get(route('portal.posts.show', $w->plainPost->slug, false))->assertOk()->getContent(), 'page');

    expect(hkParts($page, 'meta'))->toHaveCount(1)
        ->and(hkParts($page, 'back'))->toHaveCount(1)
        ->and(hkParts($page, 'lead'))->toBeEmpty()
        ->and(hkParts($page, 'cover'))->toBeEmpty();
});

it('F108 AC-7: marks the album category as meta, the description as lead and the back link on the album detail page', function () {
    $w = hkWorld();
    $described = kontenAlbum(['title' => 'Album berdeskripsi', 'description' => 'Deskripsi album uji.']);
    kontenPhoto($described, ['sort_order' => 1]);
    hkActivate(true, hkManifest());

    $html = $this->get(route('portal.albums.show', $described->slug, false))->assertOk()->getContent();
    $page = hkFirstRoot($html, 'page');
    $meta = hkParts($page, 'meta');
    $lead = hkParts($page, 'lead');
    $back = hkParts($page, 'back');

    expect($meta)->toHaveCount(1)
        ->and($meta[0]->tagName)->toBe('p')
        ->and($lead)->toHaveCount(1)
        ->and($lead[0]->tagName)->toBe('p')
        ->and(trim($lead[0]->textContent))->toBe('Deskripsi album uji.')
        ->and($back)->toHaveCount(1)
        ->and($back[0]->tagName)->toBe('a')
        ->and(hkPartCount($html, 'back'))->toBe(1)
        ->and(hkPartCount($html, 'lead'))->toBe(1);

    $plain = hkFirstRoot($this->get(route('portal.albums.show', $w->bigAlbum->slug, false))->assertOk()->getContent(), 'page');

    expect(hkParts($plain, 'meta'))->toHaveCount(1)
        ->and(hkParts($plain, 'back'))->toHaveCount(1)
        ->and(hkParts($plain, 'lead'))->toBeEmpty();
});

it('F108 AC-7: marks the contact card section on the contact page only', function () {
    hkWorld();
    portalContactData();
    StaticPage::query()->where('slug', 'kontak')->update(['status' => 'terbit', 'body' => '<p>Hubungi kami.</p>']);
    hkActivate(true, hkManifest());

    $html = $this->get('/kontak')->assertOk()->getContent();
    $page = hkFirstRoot($html, 'page');
    $contact = hkParts($page, 'contact');

    expect($contact)->toHaveCount(1)
        ->and($contact[0]->tagName)->toBe('section')
        ->and(hkPartCount($html, 'contact'))->toBe(1)
        ->and(hkParts(hkFirstRoot($this->get('/profil')->assertOk()->getContent(), 'page'), 'contact'))->toBeEmpty();
});

it('F108 AC-9: keeps every F106 component-part pair in PARTS', function () {
    $frozen = [
        'nav_menu' => ['panel', 'group', 'group-label'],
        'hero' => ['badge', 'content', 'hgroup', 'slider', 'slide'],
        'card' => ['media', 'meta', 'badge', 'date', 'title', 'summary'],
        'page' => ['page-title', 'breadcrumb', 'filters', 'pagination', 'prose', 'figure', 'section'],
        'button' => [],
    ];

    foreach ($frozen as $component => $parts) {
        expect(ThemeComponents::PARTS)->toHaveKey($component);

        foreach ($parts as $part) {
            expect(in_array($part, ThemeComponents::PARTS[$component], true))->toBeTrue("F106 {$component}/{$part} hilang dari PARTS");
        }
    }
});

/**
 * Bagian di sel "Bagian" baris dokumen sebuah komponen: yang selalu ada dan yang bertanda `*opsional:*`.
 *
 * @return array{always: list<string>, optional: list<string>}
 */
function hkDocPartCell(string $component): array
{
    $rows = hkDocRows();

    expect($rows)->toHaveKey($component);

    $cell = array_map('trim', explode('|', $rows[$component]))[3] ?? '';
    [$always, $optional] = array_pad(explode('*opsional:*', $cell, 2), 2, '');
    $names = fn (string $text): array => preg_match_all('/`([a-z][a-z0-9-]*)`/', $text, $m) ? $m[1] : [];

    return ['always' => $names($always), 'optional' => $names($optional)];
}

it('F108 AC-10: lists the always-printed new parts in PARTS and the conditional ones only as opsional in the document', function () {
    $always = ['card' => ['body'], 'text_image' => ['inner', 'content'], 'cta' => ['inner'], 'quote' => ['figure'], 'page' => ['meta', 'back']];
    $optional = ['quote' => ['mark'], 'footer_widgets' => ['name', 'logo'], 'page' => ['lead', 'cover', 'contact']];

    foreach ($always as $component => $parts) {
        foreach ($parts as $part) {
            expect(ThemeComponents::PARTS[$component])->toContain($part);
        }
    }

    foreach ($optional as $component => $parts) {
        foreach ($parts as $part) {
            expect(ThemeComponents::PARTS[$component])->not->toContain($part)
                ->and(hkDocPartCell($component)['optional'])->toContain($part)
                ->and(hkDocPartCell($component)['always'])->not->toContain($part);
        }
    }
});

it('F108 AC-10: documents the text_image inner+content nesting and that page meta varies between div and p', function () {
    $rows = hkDocRows();
    $doc = (string) file_get_contents(base_path('docs/tema-kontrak-markup.md'));

    expect($rows['text_image'])->toContain('`inner`')->toContain('`content`')
        ->and($doc)->toMatch('/`inner`[^\n]*`content`|`content`[^\n]*`inner`/')
        ->and($doc)->toMatch('/`meta`[^\n]*bervariasi|bervariasi[^\n]*`meta`/')
        ->and($rows['page'])->toContain('`contact`');
});

// ---------------------------------------------------------------------------------------------------------------------
// F108 VERIFY adversarial
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Kait baru F108 yang tidak boleh muncul di halaman di luar jangkauannya (PSB, portal wali). `name` dikecualikan karena footer ringkas PSB memang memilikinya.
 *
 * @return list<string>
 */
function hkF108OnlyParts(): array
{
    return ['inner', 'content', 'mark', 'logo', 'lead', 'cover', 'back', 'contact', 'body', 'meta', 'figure'];
}

it('F108 VERIFY: prints no F108 part on the PSB registration and status pages while a css theme is active', function (string $path) {
    hkWorld();
    hkActivate(true, hkManifest());
    $html = $this->get($path)->assertOk()->getContent();

    foreach (hkF108OnlyParts() as $part) {
        expect(hkPartCount($html, $part))->toBe(0, "{$path}: {$part} bocor ke halaman PSB");
    }
})->with(['/psb', '/psb/status']);

it('F108 VERIFY: prints no F108 part on the guardian portal while a css theme is active', function () {
    $f = waliBoot();
    hkActivate(true, hkManifest());

    foreach (['/wali', '/wali/santri/'.$f->s1->id] as $path) {
        $html = $this->actingAs($f->wali)->get($path)->assertOk()->getContent();

        foreach (hkF108OnlyParts() as $part) {
            expect(hkPartCount($html, $part))->toBe(0, "{$path}: {$part} bocor ke portal wali");
        }
    }
});

it('F108 VERIFY: renders a plain news detail, a bare album, and both footer modes without css exactly as the css theme stripped of traces', function (string $kind) {
    $w = hkWorld();
    Setting::set('school.logo_mobile_path', 'school/logo-uji.png');
    ftrSet([ftr('text', ['body' => '<p>Isi</p>', 'show_logo' => true])]);
    $bare = kontenAlbum(['title' => 'Album polos']);
    kontenPhoto($bare, ['sort_order' => 1]);
    $path = match ($kind) {
        'berita-polos' => route('portal.posts.show', $w->plainPost->slug, false),
        'album-polos' => route('portal.albums.show', $bare->slug, false),
        'footer-ringkas' => '/info-psb',
        'footer-penuh' => '/berita',
    };

    hkActivate(false, hkManifest());
    $plain = hkNormalize($this->get($path)->assertOk()->getContent());
    hkActivate(true, hkManifest());
    $themed = hkNormalize($this->get($path)->assertOk()->getContent());

    expect($plain)->not->toContain('data-pt')->not->toMatch('/<[a-z][a-z0-9-]*\s+>/i')
        ->and($themed)->toContain('data-pt-part=')
        ->and(preg_replace('/\s+/', ' ', hkStripThemeTraces($themed)))->toBe(preg_replace('/\s+/', ' ', $plain));
})->with(['berita-polos', 'album-polos', 'footer-ringkas', 'footer-penuh']);

it('F108 VERIFY: never prints two data-pt-part attributes on one tag nor repeats a part on one element across the themed pages', function (string $key) {
    $w = hkWorld();
    portalContactData();
    StaticPage::query()->where('slug', 'kontak')->update(['status' => 'terbit', 'body' => '<p>Hubungi kami.</p>']);
    ftrSet([ftr('text', ['body' => '<p>Isi</p>', 'show_logo' => true])]);
    hkBlocks('posts');
    hkActivate(true, [...hkManifest(), 'home' => ['layout' => [['component' => 'blocks', 'variant' => 'default']]]]);
    $path = $key === 'posts-show' ? route('portal.posts.show', $w->post->slug, false) : ($key === 'home' ? '/' : hkPageUrl($key, $w));
    $html = $this->get($path)->assertOk()->getContent();

    expect(preg_match('/<[a-z][a-z0-9-]*\b[^>]*\bdata-pt-part="[^>]*\bdata-pt-part="/i', $html))->toBe(0, "{$key}: atribut data-pt-part ganda pada satu tag")
        ->and(preg_match('/<[a-z][a-z0-9-]*\b[^>]*\bdata-pt="[^>]*\bdata-pt="/i', $html))->toBe(0, "{$key}: atribut data-pt ganda pada satu tag");
})->with(['home', 'posts-index', 'posts-show', 'albums-show', 'pages-show', 'psb-info']);

it('F108 VERIFY: keeps the F105 and F106 counts on the home feed and cards unchanged per root', function () {
    hkWorld();
    hkBlocks('posts');
    hkActivate(true, hkManifest());
    $html = hkNormalize(blkHome());
    $cards = hkCards($html, 'post');

    expect($cards)->not->toBeEmpty();

    foreach ($cards as $card) {
        foreach (['media', 'title', 'meta', 'date'] as $part) {
            expect(hkParts($card, $part))->toHaveCount(1, "kartu post: {$part} harus tepat satu");
        }
    }

    // F106 menjadikan `section` bagian halaman, bukan komponen lain: kait baru tidak menumpang `data-pt-part="section"` di beranda.
    expect(hkPartCount($html, 'section'))->toBe(0);
});

it('F108 VERIFY: gives the contact part to the kontak slug only, not to other static pages or the PSB info page', function (string $path) {
    hkWorld();
    portalContactData();
    StaticPage::query()->where('slug', 'kontak')->update(['status' => 'terbit', 'body' => '<p>Hubungi kami.</p>']);
    hkActivate(true, hkManifest());

    expect(hkPartCount($this->get($path)->assertOk()->getContent(), 'contact'))->toBe(0);
})->with(['/profil', '/info-psb', '/berita', '/galeri']);

it('F108 VERIFY: prints no contact part on the contact page without a css theme', function () {
    hkWorld();
    portalContactData();
    StaticPage::query()->where('slug', 'kontak')->update(['status' => 'terbit', 'body' => '<p>Hubungi kami.</p>']);
    hkActivate(false, hkManifest());

    expect($this->get('/kontak')->assertOk()->getContent())->not->toContain('data-pt');
});
