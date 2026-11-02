<?php

use App\Actions\Konten\ThemeStyleMap;
use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
| F102 AC-3, AC-4 (selain golden, lihat PortalThemeGoldenTest), AC-5, AC-7, AC-8. Kontrak yang diasumsikan:
|  - `theme.template` (Setting) berisi slug tema; tema resmi `default`, `centered`, `klasik` di `resources/themes/<slug>/theme.json`.
|  - `klasik` merender header, footer, dan seksi beranda dari JSON; gaya seksi (peta `ThemeStyleMap`) dipasang pada elemen
|    `<section>` akar tiap blok beranda. Tema tanpa `header`/`footer`/seksi beranda memakai komponen bawaan inti (hasil sama dengan `default`).
|  - Tema yang hilang atau tidak valid: portal 200 dengan chrome inti dan satu `Log::warning` per slug per proses (pesan atau konteks
|    memuat slug).
| Tema uji (`zz-uji-*`) ditulis lewat `themeInstall()` ke folder sementara `config('pondok.themes_path')` (tests/Pest.php).
*/

beforeEach(function () {
    portalBoot();
});

const PORTAL_THEMES = ['default', 'centered', 'klasik'];

/**
 * Isi portal: menu, tombol header, widget footer, dan blok beranda (ketujuh tipe, satu tersembunyi, satu kosong).
 */
function themeSeed(): void
{
    portalSeedContent();
    $video = kontenVideo(['title' => 'Video seksi sumber']);
    navSet([navItem('home', 'Beranda'), navItem('posts', 'Berita uji'), navItem('albums', 'Galeri uji')], navItem('posts', 'Kabar terbaru'));
    ftrSet([
        ftr('text', ['heading' => 'Tentang kami', 'body' => '<p>Teks footer uji.</p>']),
        ftr('links', ['heading' => 'Tautan', 'items' => [navItem('posts', 'Tautan berita uji')]]),
    ]);
    blkSet([
        blk('hero', ['eyebrow' => 'Label hero uji', 'title' => 'Judul hero uji', 'text' => 'Teks hero uji', 'image' => 'portal/blocks/hero.jpg', 'image_alt' => 'Gedung utama uji', 'primary' => navItem('posts', 'Tombol hero uji')]),
        blk('features', ['heading' => 'Keunggulan uji', 'items' => [['title' => 'Satu uji', 'text' => 'Deskripsi satu uji'], ['title' => 'Dua uji', 'text' => 'Deskripsi dua uji']]]),
        blk('feed', ['source' => 'albums', 'heading' => 'Feed uji']),
        blk('text_image', ['heading' => 'Tentang uji', 'text' => '<p>Isi tentang uji.</p>', 'image' => 'portal/blocks/tentang.jpg', 'image_alt' => 'Foto tentang uji', 'button' => navItem('posts', 'Tombol teks uji')]),
        blk('quote', ['text' => 'Teks kutipan uji', 'source' => 'Sumber uji']),
        blk('video', ['heading' => 'Video seksi uji', 'video_id' => $video->id]),
        blk('cta', ['heading' => 'Ajakan uji', 'variant' => 'light', 'primary' => navItem('posts', 'Tombol ajakan uji')]),
        blk('quote', ['text' => 'Kutipan tersembunyi uji'], visible: false),
        blk('text_image', []),
    ]);
}

function themeSquash(string $html): string
{
    return trim((string) preg_replace('/>\s+</', '><', (string) preg_replace('/\s+/', ' ', $html)));
}

/**
 * Header dan footer halaman, spasi dirapatkan.
 *
 * @return array{header: string, footer: string}
 */
function themeChrome(string $html): array
{
    preg_match('#<header\b.*?</header>#is', $html, $header);
    preg_match('#<footer\b.*?</footer>#is', $html, $footer);

    return ['header' => themeSquash($header[0] ?? ''), 'footer' => themeSquash($footer[0] ?? '')];
}

/**
 * Pasangan `href|label` semua tautan, urut dan unik.
 *
 * @return list<string>
 */
function themeLinks(string $fragment): array
{
    preg_match_all('#<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is', $fragment, $matches);

    return collect($matches[1])->map(fn (string $href, int $i): string => html_entity_decode($href).'|'.trim((string) preg_replace('/\s+/', ' ', strip_tags($matches[2][$i]))))->unique()->sort()->values()->all();
}

function themeCleanHome(): string
{
    return themeSquash(blkHome());
}

it('AC-3: renders the klasik header and footer from json with the same core data as the default chrome', function () {
    themeSeed();
    $default = themeChrome(blkHome());

    themeActivate('klasik');
    $klasik = themeChrome(blkHome());

    expect($klasik['header'])->not->toBe('')->toContain('Ma&#039;had Al-Kitab')->toContain('Kabar terbaru')
        ->and(themeLinks($klasik['header']))->toBe(themeLinks($default['header']))
        ->and(themeLinks($klasik['footer']))->toBe(themeLinks($default['footer']))
        ->and(profilVisibleText($klasik['footer']))->toContain('Teks footer uji.')->toContain('Tautan berita uji')->toContain(__('pondok.portal.footer.rights', ['year' => now()->year, 'name' => "Ma'had Al-Kitab"]))
        ->and($klasik['header'] !== $default['header'] || $klasik['footer'] !== $default['footer'])->toBeTrue('klasik harus dirender dari JSON, bukan salinan chrome inti');
});

it('AC-3: costs the same number of queries with klasik as with the core layout', function () {
    themeSeed();
    blkHome();

    // Kedua pengukuran dimulai dari keadaan dingin yang sama (cache per request dibuang oleh themeActivate).
    themeActivate(null);
    DB::enableQueryLog();
    DB::flushQueryLog();
    blkHome();
    $default = count(DB::getQueryLog());

    themeActivate('klasik');
    DB::flushQueryLog();
    blkHome();

    expect($default)->toBeGreaterThan(0)->and(count(DB::getQueryLog()))->toBe($default);
});

it('AC-3: keeps school text escaped and unsafe urls out of the klasik header and footer', function () {
    themeSeed();
    Setting::set('school.name', '<script>alert(1)</script>Pondok');
    navSet([navItem('home', 'Beranda'), navItem('url', 'Tautan jahat', ['url' => 'javascript:alert(1)'])], navItem('url', 'Tombol jahat', ['url' => 'javascript:alert(2)']));
    ftrSet([ftr('text', ['heading' => 'Tentang', 'body' => '<p onclick="alert(3)">Isi</p><script>alert(4)</script>']), ftr('links', ['items' => [navItem('url', 'Tautan footer jahat', ['url' => 'javascript:alert(5)'])]])]);
    themeActivate('klasik');

    $html = blkHome();
    $chrome = themeChrome($html);

    expect($chrome['header'].$chrome['footer'])->not->toContain('<script')->not->toContain('javascript:')->not->toContain('onclick')
        ->and($chrome['header'])->toContain('Pondok');
});

it('AC-3: falls back to the core header and footer for a theme without them, and renders a theme without any mapping exactly like the core', function () {
    themeSeed();
    $default = blkHome();
    $klasik = themeOfficialManifest('klasik');
    $slug = themeInstall(Arr::except($klasik, ['header', 'footer']));

    themeActivate($slug);
    $withoutChrome = blkHome();

    expect(themeChrome($withoutChrome))->toBe(themeChrome($default));

    $blank = themeInstall(Arr::except($klasik, ['header', 'footer', 'home']));
    themeActivate($blank);

    expect(themeSquash(blkHome()))->toBe(themeSquash($default), 'tipe blok yang tidak dipetakan memakai komponen bawaan inti');
});

it('AC-3: carries no portal text in the theme files and shows no manifest text on the portal', function () {
    themeSeed();
    $forbidden = ['text', 'label', 'title', 'heading', 'body', 'html', 'content', 'url', 'href', 'src'];

    foreach (PORTAL_THEMES as $slug) {
        $manifest = themeOfficialManifest($slug);
        $keys = collect(Arr::dot($manifest))->keys()->map(fn (string $key): array => explode('.', $key))->flatten()->reject(fn (string $key): bool => is_numeric($key))->unique()->all();

        expect(array_intersect($keys, $forbidden))->toBe([], "{$slug}: kunci bersifat teks/URL tidak boleh ada di manifest");

        themeActivate($slug);
        $visible = profilVisibleText(blkHome());

        expect($visible)->not->toContain($manifest['description'])->not->toContain($manifest['name'], "{$slug}: nama tema tidak tampil di portal");
    }
});

it('AC-4: shows every block in order for each theme, drops hidden and empty blocks and escapes block text', function () {
    themeSeed();
    $blocks = blkStored();
    $blocks[0]['data']['text'] = '<img src=x onerror=alert(1)> Teks hero uji';
    $blocks[0]['data']['title'] = 'Judul hero uji <script>alert(2)</script>';
    blkSet($blocks);
    $needles = ['Judul hero uji', 'Keunggulan uji', 'Feed uji', 'Tentang uji', 'Teks kutipan uji', 'Video seksi uji', 'Ajakan uji'];

    foreach (PORTAL_THEMES as $slug) {
        themeActivate($slug);
        $html = blkHome();
        $order = array_map(fn (string $needle): int|false => mb_strpos($html, $needle), $needles);

        expect($order)->not->toContain(false, "{$slug}: semua blok tampil")
            ->and($order)->toBe(collect($order)->sort()->values()->all(), "{$slug}: urutan blok")
            ->and($html)->not->toContain('Kutipan tersembunyi uji')
            ->and($html)->not->toContain('<img src=x')->not->toContain('<script>alert(2)')
            ->and(substr_count($html, '<h1'))->toBe(1, "{$slug}: tepat satu h1");
    }
});

it('AC-4: alternates tone auto backgrounds like the old band closure and keeps unmapped block types on the core component', function () {
    themeSeed();
    $klasik = themeOfficialManifest('klasik');
    $manifest = [...$klasik, 'home' => ['sections' => array_map(fn (): array => ['style' => ['tone' => 'auto']], array_flip(['features', 'feed', 'text_image', 'quote', 'video', 'cta']))]];

    themeActivate(themeInstall($manifest));
    $html = blkHome();
    preg_match_all('#<section\b[^>]*class="([^"]*)"#i', $html, $sectionTags);
    $backgrounds = array_map(fn (string $class): string => collect(explode(' ', $class))->first(fn (string $token): bool => str_starts_with($token, 'bg-')) ?? '', array_slice($sectionTags[1], 1));

    expect($html)->toContain('Judul hero uji')
        ->and(substr_count($html, '<h1'))->toBe(1, 'hero tak dipetakan memakai komponen inti')
        ->and($backgrounds)->toBe(['bg-gray-50', 'bg-white', 'bg-gray-50', 'bg-white', 'bg-gray-50', 'bg-white'], 'tone auto selang-seling mulai dari bg-gray-50, tanpa hero');
});

it('AC-4: puts the klasik style map classes on each mapped block section and no other padding, gap or column utilities there', function () {
    themeSeed();
    $manifest = themeOfficialManifest('klasik');
    themeActivate('klasik');
    $html = blkHome();
    $expected = collect($manifest['home']['sections'] ?? [])
        ->filter(fn (array $node): bool => isset($node['style']) && ($node['style']['tone'] ?? null) !== 'auto')
        ->map(fn (array $node): array => preg_split('/\s+/', trim(ThemeStyleMap::classes(Arr::except($node['style'], ['tone'])))));
    preg_match_all('#<section\b[^>]*class="([^"]*)"#i', $html, $tags);
    $classes = collect($tags[1])->map(fn (string $class): array => array_values(array_filter(explode(' ', $class))));
    $owned = fn (array $tokens): array => array_values(array_filter($tokens, fn (string $token): bool => preg_match('/^(?:(?:md|lg):)?(?:py|gap|grid-cols)-/', $token) === 1));
    $allowed = collect(['padding', 'gap', 'columns'])->flatMap(fn (string $property): array => collect(['padding' => ['sm', 'md', 'lg'], 'gap' => ['sm', 'md', 'lg'], 'columns' => [2, 3, 4]][$property])->flatMap(fn (mixed $value): array => [
        ...preg_split('/\s+/', trim(ThemeStyleMap::classes([$property => $value]))),
        ...(in_array($property, ['padding', 'columns'], true)
            ? preg_split('/\s+/', trim(ThemeStyleMap::classes([$property => ['base' => $value, 'md' => $value, 'lg' => $value]])))
            : []),
    ])->all())->filter()->unique()->all();

    expect($expected)->not->toBeEmpty('manifest klasik harus memberi gaya pada setidaknya satu seksi beranda');

    foreach ($classes as $tokens) {
        expect(array_diff($owned($tokens), $allowed))->toBe([], 'section memuat kelas padding/gap/kolom di luar peta gaya');
    }

    foreach ($expected as $type => $tokens) {
        expect($classes->contains(fn (array $section): bool => array_diff($tokens, $section) === []))->toBeTrue("seksi {$type}: kelas peta gaya tidak terpasang pada <section>");
    }
});

it('AC-5: gives every official theme a skip link, one main, one h1, orderly headings, two labelled navs and visible focus on every control', function () {
    themeSeed();

    foreach (PORTAL_THEMES as $slug) {
        themeActivate($slug);
        $html = blkHome();
        $body = (string) preg_replace('#^.*?<body\b[^>]*>#is', '', $html);
        $header = themeChrome($html)['header'];
        preg_match_all('#<h([1-6])\b#i', $html, $levels);
        $levels = array_map('intval', $levels[1]);
        $navLabels = portalNavLabels($header);
        preg_match_all('#<nav\b[^>]*>#i', $html, $allNavs);
        preg_match_all('#<(?:a|button|summary)\b[^>]*>#i', $body, $controls);

        expect(preg_match('#^\s*<a\b[^>]*href="\#konten"#i', $body))->toBe(1, "{$slug}: tautan lompat adalah elemen fokus pertama")
            ->and(substr_count($html, '<main'))->toBe(1, "{$slug}: satu main")
            ->and($html)->toMatch('#<main\b[^>]*id="konten"#i')
            ->and(substr_count($html, '<h1'))->toBe(1, "{$slug}: satu h1")
            ->and($header)->not->toContain('<h1', "{$slug}: brand bukan h1")
            ->and($levels[0] ?? null)->toBe(1, "{$slug}: heading pertama h1");

        foreach ($levels as $index => $level) {
            expect($level)->toBeLessThanOrEqual(($levels[$index - 1] ?? 0) + 1, "{$slug}: loncatan level heading di urutan ke-{$index}");
        }

        expect(array_filter($allNavs[0], fn (string $tag): bool => ! str_contains($tag, 'aria-label') && ! str_contains($tag, 'aria-labelledby')))->toBe([], "{$slug}: nav tanpa nama")
            ->and($navLabels)->toHaveCount(2, "{$slug}: dua nav di header")
            ->and(array_filter($navLabels, fn (string $label): bool => $label === ''))->toBe([], "{$slug}: nav tanpa aria-label")
            ->and(array_unique($navLabels))->toHaveCount(2, "{$slug}: aria-label nav berbeda");

        $withoutFocus = array_values(array_filter($controls[0], fn (string $tag): bool => ! str_contains($tag, 'focus-visible:') && ! str_contains($tag, 'focus:')));

        expect($controls[0])->not->toBeEmpty()
            ->and($withoutFocus)->toBe([], "{$slug}: kontrol tanpa kelas fokus terlihat");
    }
});

it('AC-7: serves every official theme with public cache headers, etag and 304, without tokens, livewire or cookies', function () {
    themeSeed();
    Setting::set('modules.psb.enabled', true);
    psbInfoPage(['status' => 'terbit']);

    foreach (PORTAL_THEMES as $slug) {
        themeActivate($slug);

        foreach (['/', '/berita', route('psb.info', absolute: false)] as $url) {
            $response = $this->get($url)->assertOk();
            $etag = $response->headers->get('ETag');
            $html = strtolower($response->getContent());

            expect($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=300')
                ->and($etag)->not->toBeNull("{$slug} {$url}: ETag")
                ->and($response->headers->getCookies())->toBeEmpty("{$slug} {$url}: tanpa cookie")
                ->and($this->withHeader('If-None-Match', (string) $etag)->get($url)->getStatusCode())->toBe(304, "{$slug} {$url}: 304");

            foreach (['csrf-token', 'xsrf', '_token', 'livewire'] as $needle) {
                expect($html)->not->toContain($needle, "{$slug} {$url}: {$needle}");
            }
        }
    }
});

it('AC-7: keeps the core chrome on session pages, 404 and verification pages while klasik is active', function () {
    Setting::set('modules.psb.enabled', true);
    portalPsbOpen();
    $urls = [route('psb.register', absolute: false), route('portal-wali.login', absolute: false), '/tidak-ada-f102', '/verifikasi-surat'];
    $before = array_map(fn (string $url): array => themeChrome($this->get($url)->getContent()), $urls);

    themeActivate('klasik');

    foreach ($urls as $index => $url) {
        expect(themeChrome($this->get($url)->getContent()))->toBe($before[$index], $url);
    }

    expect($before[0]['header'])->not->toBe('', 'psb.register memakai chrome inti');
});

it('AC-8: falls back to the core chrome with a 200 and one warning per slug when the active theme is missing or unusable', function () {
    themeSeed();
    $default = themeSquash(blkHome());
    $klasik = themeOfficialManifest('klasik');
    $json = json_encode($klasik);
    $cases = [
        'hilang' => themeUniqueSlug(),
        'JSON rusak' => themeInstall('{"schema_version":1,'),
        'schema 99' => themeInstall([...$klasik, 'schema_version' => 99]),
        'melebihi 64 KB' => themeInstall($json.str_repeat(' ', 70 * 1024)),
        'min_core terlalu baru' => themeInstall([...$klasik, 'min_core' => '999.0.0']),
    ];

    Log::spy();

    foreach ($cases as $label => $slug) {
        themeActivate($slug);

        expect(themeSquash(blkHome()))->toBe($default, "{$label}: beranda memakai chrome dan komponen inti");
        $this->get('/berita')->assertOk();
        $this->get('/')->assertOk();
    }

    foreach ($cases as $label => $slug) {
        Log::shouldHaveReceived('warning')->withArgs(fn (...$args): bool => str_contains(json_encode($args), $slug))->once();
    }
});
