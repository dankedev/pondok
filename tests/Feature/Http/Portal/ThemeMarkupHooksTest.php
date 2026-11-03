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
    ];
    $homes = [
        'beranda-center-posts-cards' => ['posts', null, ['center', 'cards', 'band']],
        'beranda-split-albums-magazine' => ['albums', $w->album, ['split', 'magazine', 'card']],
        'beranda-center-videos-cards' => ['videos', null, ['center', 'cards', 'band']],
    ];

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
    return ['beranda-center-posts-cards', 'beranda-split-albums-magazine', 'beranda-center-videos-cards', 'beranda-blocks', 'posts-index', 'posts-show', 'albums-index', 'albums-show', 'videos-index', 'pages-show', 'psb-info'];
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
})->with(hkSnapshotDataset(['beranda-center-posts-cards', 'beranda-split-albums-magazine', 'beranda-blocks', 'posts-index', 'albums-show', 'psb-info']));

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
        expect($rows)->toHaveKey($component, "{$component}: baris dokumen");

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
