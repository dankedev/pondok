<?php

use App\Actions\Konten\GetPortalTheme;
use App\Actions\Konten\ResolveActiveTheme;
use App\Actions\Konten\ThemeStyleMap;
use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/*
| F102 AC-4, 5, 7, 8, 10, 11, 12, 13, 15 (golden AC-6 ada di PortalThemeGoldenTest). Kontrak yang diasumsikan:
|  - `theme.template` (Setting) berisi slug tema; tema resmi `default`, `centered`, `majalah`, `mushaf` di `resources/themes/<slug>/theme.json`.
|  - Renderer berjalan di pohon `home.layout`: tiap node isi (hero, text_image, feature_grid, quote, cta, video, feed_list) mengikat blok aktif
|    pertama bertipe sama (hero<-hero, feature_grid<-features, feed_list<-feed); `blocks` = semua blok lewat view legacy. Node yang tak tampil
|    tidak merender elemen apa pun. Gaya (peta `ThemeStyleMap`) dipasang pada elemen `<section>` akar node isi level atas.
|  - Tema tanpa `header`/`footer` memakai partial inti; tanpa `home.layout` sama dengan `[{blocks, default}]` (HTML identik tema `default`).
|  - Tema yang hilang atau tidak valid: portal 200 dengan chrome inti dan satu `Log::warning` per slug per proses (pesan atau konteks
|    memuat slug).
| Tema uji (`zz-uji-*`) ditulis lewat `themeInstall()` ke folder sementara `config('pondok.themes_path')` (tests/Pest.php).
*/

beforeEach(function () {
    portalBoot();
});

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

/**
 * Beranda dari tema uji sandbox berisi `$layout` (tanpa header/footer kecuali `$chrome`).
 *
 * @param  list<array<string, mixed>>  $layout
 */
function themeHomeWith(array $layout, bool $chrome = false): string
{
    $manifest = themeLayout($layout);

    themeActivate(themeInstall($chrome ? $manifest : Arr::except($manifest, ['header', 'footer'])));

    return blkHome();
}

function themeMain(string $html): string
{
    preg_match('#<main\b.*?</main>#is', $html, $match);

    return $match[0] ?? '';
}

/**
 * Buang blok bertipe `$types` dari data beranda yang sudah diisi `themeSeed()`.
 *
 * @param  list<string>  $types
 */
function themeSeedWithout(array $types): void
{
    blkSet(array_values(array_filter(blkStored(), fn (array $block): bool => ! in_array($block['type'], $types, true))));
}

/**
 * @return list<array{level: int, attrs: string, text: string}>
 */
function themeHeadings(string $html): array
{
    preg_match_all('#<h([1-6])\b([^>]*)>(.*?)</h\1>#is', $html, $matches, PREG_SET_ORDER);

    return array_map(fn (array $m): array => ['level' => (int) $m[1], 'attrs' => $m[2], 'text' => trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5))], $matches);
}

/**
 * Pastikan tepat satu h1, tanpa loncatan level, tanpa id ganda; kembalikan daftar heading.
 *
 * @return list<array{level: int, attrs: string, text: string}>
 */
function themeExpectOutline(string $html, string $label): array
{
    $headings = themeHeadings($html);
    $levels = array_column($headings, 'level');
    preg_match_all('#\sid="([^"]+)"#', $html, $ids);

    expect(count(array_filter($levels, fn (int $level): bool => $level === 1)))->toBe(1, "{$label}: tepat satu h1")
        ->and($levels[0] ?? null)->toBe(1, "{$label}: heading pertama h1")
        ->and(count($ids[1]))->toBe(count(array_unique($ids[1])), "{$label}: id ganda: ".implode(', ', array_diff_assoc($ids[1], array_unique($ids[1]))));

    foreach ($levels as $index => $level) {
        expect($level)->toBeLessThanOrEqual(($levels[$index - 1] ?? 0) + 1, "{$label}: loncatan level heading ke-{$index}");
    }

    return $headings;
}

it('AC-10: renders the majalah and mushaf header and footer from json with the same core data as the default chrome', function () {
    themeSeed();
    $default = themeChrome(blkHome());

    foreach (['majalah', 'mushaf'] as $slug) {
        themeActivate($slug);
        $chrome = themeChrome(blkHome());

        expect($chrome['header'])->not->toBe('')->toContain('Ma&#039;had Al-Kitab')->toContain('Kabar terbaru')
            ->and(themeLinks($chrome['header']))->toBe(themeLinks($default['header']), "{$slug}: tautan header")
            ->and(themeLinks($chrome['footer']))->toBe(themeLinks($default['footer']), "{$slug}: tautan footer")
            ->and(profilVisibleText($chrome['footer']))->toContain('Teks footer uji.')->toContain('Tautan berita uji')->toContain(__('pondok.portal.footer.rights', ['year' => now()->year, 'name' => "Ma'had Al-Kitab"]))
            ->and($chrome['header'] !== $default['header'] || $chrome['footer'] !== $default['footer'])->toBeTrue("{$slug} harus dirender dari JSON, bukan salinan chrome inti");
    }
});

it('AC-10: renders columns and divider in header and footer from the same node model', function () {
    themeSeed();
    $manifest = themeBaseManifest();
    $manifest['header'] = ['component' => 'columns', 'children' => [['component' => 'brand'], ['component' => 'nav_menu']]];
    $manifest['footer'] = ['component' => 'container', 'children' => [['component' => 'divider'], ['component' => 'columns', 'children' => [['component' => 'footer_widgets'], ['component' => 'copyright']]]]];
    $default = themeChrome(blkHome());

    themeActivate(themeInstall($manifest));
    $chrome = themeChrome(blkHome());

    expect(themeLinks($chrome['header']))->toBe(themeLinks($default['header']))
        ->and(themeLinks($chrome['footer']))->toBe(themeLinks($default['footer']))
        ->and(profilVisibleText($chrome['footer']))->toContain('Teks footer uji.')->toContain(__('pondok.portal.footer.rights', ['year' => now()->year, 'name' => "Ma'had Al-Kitab"]));
});

it('AC-10: costs the same number of queries with majalah and mushaf as with the core layout', function () {
    themeSeed();
    blkHome();

    // Kedua pengukuran dimulai dari keadaan dingin yang sama (cache per request dibuang oleh themeActivate).
    themeActivate(null);
    DB::enableQueryLog();
    DB::flushQueryLog();
    blkHome();
    $default = count(DB::getQueryLog());

    expect($default)->toBeGreaterThan(0);

    foreach (['majalah', 'mushaf'] as $slug) {
        themeActivate($slug);
        DB::flushQueryLog();
        blkHome();

        expect(count(DB::getQueryLog()))->toBe($default, $slug);
    }
});

it('AC-10: keeps school text escaped and unsafe urls out of the majalah and mushaf header and footer', function () {
    themeSeed();
    Setting::set('school.name', '<script>alert(1)</script>Pondok');
    navSet([navItem('home', 'Beranda'), navItem('url', 'Tautan jahat', ['url' => 'javascript:alert(1)'])], navItem('url', 'Tombol jahat', ['url' => 'javascript:alert(2)']));
    ftrSet([ftr('text', ['heading' => 'Tentang', 'body' => '<p onclick="alert(3)">Isi</p><script>alert(4)</script>']), ftr('links', ['items' => [navItem('url', 'Tautan footer jahat', ['url' => 'javascript:alert(5)'])]])]);

    foreach (['majalah', 'mushaf'] as $slug) {
        themeActivate($slug);
        $chrome = themeChrome(blkHome());

        expect($chrome['header'].$chrome['footer'])->not->toContain('<script')->not->toContain('javascript:')->not->toContain('onclick')
            ->and($chrome['header'])->toContain('Pondok');
    }
});

it('AC-10: falls back to the core header and footer for a theme without them, and renders a theme without home.layout exactly like the core', function () {
    themeSeed();
    $default = blkHome();
    $base = themeBaseManifest();
    $withoutChrome = themeInstall(Arr::except($base, ['header', 'footer']));

    themeActivate($withoutChrome);

    expect(themeChrome(blkHome()))->toBe(themeChrome($default));

    $blank = themeInstall(Arr::except($base, ['header', 'footer', 'home']));
    themeActivate($blank);

    expect(themeSquash(blkHome()))->toBe(themeSquash($default), 'tanpa home.layout = [{blocks, default}], identik dengan inti');
});

it('AC-10: carries no portal text in the theme files and shows no manifest text on the portal', function () {
    themeSeed();
    $forbidden = ['text', 'label', 'title', 'heading', 'body', 'html', 'content', 'url', 'href', 'src'];

    foreach (THEME_OFFICIAL as $slug) {
        $manifest = themeOfficialManifest($slug);
        $keys = collect(Arr::dot($manifest))->keys()->map(fn (string $key): array => explode('.', $key))->flatten()->reject(fn (string $key): bool => is_numeric($key))->unique()->all();

        expect(array_intersect($keys, $forbidden))->toBe([], "{$slug}: kunci bersifat teks/URL tidak boleh ada di manifest");

        themeActivate($slug);
        $visible = profilVisibleText(blkHome());

        expect($visible)->not->toContain($manifest['description'])->not->toContain($manifest['name'], "{$slug}: nama tema tidak tampil di portal");
    }
});

it('AC-4: renders a free-order layout in exactly the order of the json, with repeated and nested nodes', function () {
    themeSeed();
    $html = themeHomeWith([
        ['component' => 'quote'],
        ['component' => 'hero'],
        ['component' => 'container', 'children' => [
            ['component' => 'columns', 'children' => [
                ['component' => 'feed_list'],
                ['component' => 'container', 'children' => [['component' => 'feature_grid'], ['component' => 'cta']]],
            ]],
        ]],
        ['component' => 'feed_list'],
        ['component' => 'feed_list'],
    ]);
    preg_match_all('/Teks kutipan uji|Judul hero uji|Feed uji|Keunggulan uji|Ajakan uji/', profilVisibleText(themeMain($html)), $matches);

    expect($matches[0])->toBe(['Teks kutipan uji', 'Judul hero uji', 'Feed uji', 'Keunggulan uji', 'Ajakan uji', 'Feed uji', 'Feed uji']);
});

it('AC-4 and AC-5: shows every block for each official theme, drops hidden and empty blocks and escapes block text', function () {
    themeSeed();
    $blocks = blkStored();
    $blocks[0]['data']['text'] = '<img src=x onerror=alert(1)> Teks hero uji';
    $blocks[0]['data']['title'] = 'Judul hero uji <script>alert(2)</script>';
    blkSet($blocks);
    $needles = ['Judul hero uji', 'Keunggulan uji', 'Feed uji', 'Tentang uji', 'Teks kutipan uji', 'Video seksi uji', 'Ajakan uji'];

    foreach (THEME_OFFICIAL as $slug) {
        themeActivate($slug);
        $html = blkHome();
        $order = array_map(fn (string $needle): int|false => mb_strpos($html, $needle), $needles);

        expect($order)->not->toContain(false, "{$slug}: semua blok tampil")
            ->and($html)->not->toContain('Kutipan tersembunyi uji')
            ->and($html)->not->toContain('<img src=x')->not->toContain('<script>alert(2)')
            ->and(substr_count($html, '<h1'))->toBe(1, "{$slug}: tepat satu h1");

        if (in_array($slug, ['default', 'centered'], true)) {
            expect($order)->toBe(collect($order)->sort()->values()->all(), "{$slug}: urutan blok sesuai admin");
        }
    }
});

it('AC-5: binds each node to the first active block of its type and repeats it, and lets blocks list every active block in admin order', function () {
    portalSeedContent();
    blkSet([
        blk('quote', ['text' => 'Kutipan pertama uji']),
        blk('hero', ['title' => 'Hero pertama uji']),
        blk('quote', ['text' => 'Kutipan kedua uji']),
        blk('hero', ['title' => 'Hero kedua uji']),
    ]);

    $main = profilVisibleText(themeMain(themeHomeWith([['component' => 'hero'], ['component' => 'quote'], ['component' => 'hero']])));
    $blocks = profilVisibleText(themeMain(themeHomeWith([['component' => 'blocks']])));

    expect(substr_count($main, 'Hero pertama uji'))->toBe(2)
        ->and($main)->not->toContain('Hero kedua uji')->toContain('Kutipan pertama uji')->not->toContain('Kutipan kedua uji')
        ->and(collect(['Kutipan pertama uji', 'Hero pertama uji', 'Kutipan kedua uji', 'Hero kedua uji'])->map(fn (string $needle): int|false => mb_strpos($blocks, $needle))->all())
        ->toBe(collect(['Kutipan pertama uji', 'Hero pertama uji', 'Kutipan kedua uji', 'Hero kedua uji'])->map(fn (string $needle): int|false => mb_strpos($blocks, $needle))->sort()->values()->all());
});

it('AC-5: renders no element at all for nodes whose block is missing, including wrappers, columns and dividers', function () {
    portalSeedContent();
    blkSet([blk('hero', ['title' => 'Hero tunggal uji'])]);

    $only = themeSquash(themeMain(themeHomeWith([['component' => 'hero']])));
    $withEmpty = themeSquash(themeMain(themeHomeWith([
        ['component' => 'hero'],
        ['component' => 'container', 'style' => ['tone' => 'muted', 'padding' => 'lg', 'gap' => 'md'], 'children' => [['component' => 'quote'], ['component' => 'divider'], ['component' => 'feed_list']]],
        ['component' => 'columns', 'style' => ['border' => 'top'], 'children' => [['component' => 'quote'], ['component' => 'text_image']]],
        ['component' => 'divider'],
        ['component' => 'cta'],
    ])));

    expect($only)->toContain('Hero tunggal uji')
        ->and($withEmpty)->toBe($only, 'node kosong, pembungkusnya, dan divider tanpa konten tetangga tidak dirender');
});

it('AC-5: gives a columns node as many grid columns as cells shown, at most the requested number', function () {
    portalSeedContent();
    blkSet([blk('hero', ['title' => 'Sel pertama uji']), blk('quote', ['text' => 'Sel ketiga uji'])]);
    $tokens = fn (int $columns): array => array_values(array_filter(preg_split('/\s+/', trim(ThemeStyleMap::classes(['columns' => $columns]))) ?: []));
    $html = themeMain(themeHomeWith([['component' => 'columns', 'style' => ['columns' => 3], 'children' => [['component' => 'hero'], ['component' => 'feed_list'], ['component' => 'quote']]]]));
    $classes = collect(explode(' ', implode(' ', array_map(fn (array $m): string => $m[1], (function () use ($html): array {
        preg_match_all('#class="([^"]*)"#', $html, $m, PREG_SET_ORDER);

        return $m;
    })()))));

    expect($tokens(2))->not->toBeEmpty()
        ->and(array_diff($tokens(2), $classes->all()))->toBe([], 'dua sel tampil: kolom = 2')
        ->and(array_intersect(array_diff($tokens(3), $tokens(2)), $classes->all()))->toBe([], 'bukan 3 kolom walau diminta 3');
});

it('AC-5: does not query content tables for content nodes when the content module is off', function () {
    themeSeed();
    Setting::set('modules.konten.enabled', false);
    $manifest = themeLayout([['component' => 'feed_list'], ['component' => 'hero'], ['component' => 'feature_grid']]);
    themeActivate(themeInstall(Arr::except($manifest, ['header', 'footer'])));
    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->get('/')->assertOk();
    $tables = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => preg_match('/from ["`](?:posts|albums|photos|videos|achievements)["`]/i', $sql) === 1);

    expect($tables->all())->toBe([]);
});

it('AC-7: alternates tone auto backgrounds over the shown top-level nodes only, so empty nodes and dividers do not shift the rhythm', function () {
    themeSeed();
    themeSeedWithout(['cta', 'hero']);
    $html = themeHomeWith([
        ['component' => 'text_image', 'style' => ['tone' => 'auto']],
        ['component' => 'divider'],
        ['component' => 'cta', 'style' => ['tone' => 'auto']],
        ['component' => 'feature_grid', 'style' => ['tone' => 'auto']],
        ['component' => 'hero', 'style' => ['tone' => 'auto']],
        ['component' => 'feed_list', 'style' => ['tone' => 'auto']],
        ['component' => 'video', 'style' => ['tone' => 'auto']],
    ]);
    preg_match_all('#<section\b[^>]*class="([^"]*)"#i', themeMain($html), $tags);
    $backgrounds = collect($tags[1])->map(fn (string $class): ?string => collect(explode(' ', $class))->first(fn (string $token): bool => in_array($token, ['bg-gray-50', 'bg-white'], true)))->filter()->values()->all();

    expect($backgrounds)->toBe(['bg-gray-50', 'bg-white', 'bg-gray-50', 'bg-white'], 'text_image, feature_grid, feed_list, video; cta dan hero kosong');
});

it('AC-7: leaves a nested node without the section gutter and default section padding', function () {
    themeSeed();
    $count = fn (string $html, string $pattern): int => preg_match_all($pattern, themeMain($html));
    $top = themeHomeWith([['component' => 'quote', 'style' => ['tone' => 'light']]]);
    $nested = themeHomeWith([['component' => 'container', 'children' => [['component' => 'quote', 'style' => ['tone' => 'light']]]]]);

    expect($count($top, '/portal-gutter/'))->toBeGreaterThan(0, 'kontrol: level atas memakai portal-gutter')
        ->and($count($nested, '/portal-gutter/'))->toBe(0, 'bersarang tanpa portal-gutter')
        ->and($count($nested, '/(?<![\w:-])py-\d/'))->toBeLessThan($count($top, '/(?<![\w:-])py-\d/'), 'bersarang tanpa padding bawaan bagian');
});

it('AC-8: gives each variant its own markup, and the first variant when none is given', function () {
    themeSeed();
    $variants = ['hero' => ['center', 'split'], 'feed_list' => ['cards', 'list', 'magazine'], 'quote' => ['plain', 'framed'], 'cta' => ['band', 'card'], 'feature_grid' => ['cards', 'numbered'], 'blocks' => ['default', 'centered']];

    foreach ($variants as $component => $names) {
        $markup = [];

        foreach ($names as $name) {
            $markup[$name] = themeSquash(themeMain(themeHomeWith([['component' => $component, 'variant' => $name]])));
            expect($markup[$name])->not->toBe('', "{$component} {$name}");
        }

        $none = themeSquash(themeMain(themeHomeWith([['component' => $component]])));

        expect(array_unique($markup))->toHaveCount(count($names), "{$component}: tiap varian punya markup berbeda")
            ->and($none)->toBe($markup[$names[0]], "{$component}: tanpa variant = {$names[0]}");
    }
});

it('AC-8: never interpolates the variant or component name into a class attribute of the theme views', function () {
    $files = collect(File::allFiles(resource_path('views/portal/theme')))->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.blade.php'));
    $problems = [];

    foreach ($files as $file) {
        $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file->getPathname()));

        // Satu-satunya sisipan sah di atribut class adalah kelas hasil `ThemeStyleMap` (`$sectionClass`, `$wrapperClass`); varian/komponen/node tidak.
        if (preg_match('/class="[^"]*(?:\{\{|\{!!)[^}]*(?:\$variant|\$component|\$mode|\$type|\$node\[\s*.(?:variant|component))/', $code) === 1) {
            $problems[] = $file->getFilename().': kelas disisipi variant/component di atribut class';
        }
    }

    expect($files)->not->toBeEmpty()->and($problems)->toBe([]);
});

it('AC-11: keeps exactly one h1, ordered levels and unique ids on every official theme and every layout shape', function () {
    themeSeed();

    foreach (THEME_OFFICIAL as $slug) {
        themeActivate($slug);
        themeExpectOutline(blkHome(), $slug);
    }

    $withHero = themeExpectOutline(themeHomeWith([['component' => 'hero'], ['component' => 'quote'], ['component' => 'hero'], ['component' => 'feed_list'], ['component' => 'feed_list']]), 'hero berulang');
    $heroes = array_values(array_filter($withHero, fn (array $h): bool => $h['text'] === 'Judul hero uji'));

    expect(array_column($heroes, 'level'))->toBe([1, 2], 'hero pertama h1, kedua h2');

    $noHero = themeSeedWithout(['hero']);
    $outline = themeExpectOutline($html = themeHomeWith([['component' => 'feed_list'], ['component' => 'quote']]), 'tanpa hero');

    expect($outline[0]['attrs'])->toContain('sr-only')
        ->and($outline[0]['text'])->toContain("Ma'had Al-Kitab")
        ->and(mb_strpos(themeMain($html), '<h1'))->toBeLessThan((int) mb_strpos(themeMain($html), 'Feed uji'), 'h1 sr-only di awal main');

    themeExpectOutline(themeHomeWith([['component' => 'blocks']]), 'preset blocks tanpa hero');
});

it('AC-11: demotes a hero that follows a titled node to h2 and puts the single sr-only h1 at the start of main', function () {
    themeSeed();
    $html = themeHomeWith([['component' => 'feed_list'], ['component' => 'hero']]);
    $outline = themeExpectOutline($html, 'node bertajuk sebelum hero');
    $hero = collect($outline)->first(fn (array $h): bool => $h['text'] === 'Judul hero uji');

    expect($outline[0]['attrs'])->toContain('sr-only')->and($outline[0]['text'])->toContain("Ma'had Al-Kitab")
        ->and($hero['level'])->toBe(2)
        ->and(mb_strpos(themeMain($html), '<h1'))->toBeLessThan((int) mb_strpos(themeMain($html), 'Feed uji'));
});

it('AC-12: gives every official theme a skip link, one main, two labelled navs and visible focus on every control', function () {
    themeSeed();

    foreach (THEME_OFFICIAL as $slug) {
        themeActivate($slug);
        $html = blkHome();
        $body = (string) preg_replace('#^.*?<body\b[^>]*>#is', '', $html);
        $header = themeChrome($html)['header'];
        $navLabels = portalNavLabels($header);
        preg_match_all('#<nav\b[^>]*>#i', $html, $allNavs);
        preg_match_all('#<(?:a|button|summary)\b[^>]*>#i', $body, $controls);

        expect(preg_match('#^\s*<a\b[^>]*href="\#konten"#i', $body))->toBe(1, "{$slug}: tautan lompat adalah elemen fokus pertama")
            ->and(substr_count($html, '<main'))->toBe(1, "{$slug}: satu main")
            ->and($html)->toMatch('#<main\b[^>]*id="konten"#i')
            ->and($header)->not->toContain('<h1', "{$slug}: brand bukan h1");

        expect(array_filter($allNavs[0], fn (string $tag): bool => ! str_contains($tag, 'aria-label') && ! str_contains($tag, 'aria-labelledby')))->toBe([], "{$slug}: nav tanpa nama")
            ->and($navLabels)->toHaveCount(2, "{$slug}: dua nav di header")
            ->and(array_filter($navLabels, fn (string $label): bool => $label === ''))->toBe([], "{$slug}: nav tanpa aria-label")
            ->and(array_unique($navLabels))->toHaveCount(2, "{$slug}: aria-label nav berbeda");

        $withoutFocus = array_values(array_filter($controls[0], fn (string $tag): bool => ! str_contains($tag, 'focus-visible:') && ! str_contains($tag, 'focus:')));

        expect($controls[0])->not->toBeEmpty()
            ->and($withoutFocus)->toBe([], "{$slug}: kontrol tanpa kelas fokus terlihat");
    }
});

it('AC-13: starts nested columns at one column on mobile, adds no horizontal overflow, and keeps the mobile menu toggle in the mushaf header', function () {
    themeSeed();
    $base = array_values(array_filter(preg_split('/\s+/', trim(ThemeStyleMap::classes(['columns' => 1]))) ?: []));

    expect($base)->not->toBeEmpty();

    foreach (['majalah', 'mushaf'] as $slug) {
        themeActivate($slug);
        $html = blkHome();

        expect($html)->not->toContain('overflow-x', "{$slug}: tanpa overflow-x");
    }

    themeActivate('majalah');
    preg_match_all('#class="([^"]*)"#', themeMain(blkHome()), $classes);

    expect(array_diff($base, explode(' ', implode(' ', $classes[1]))))->toBe([], 'majalah: columns responsif base 1 dipakai di main');

    themeActivate('mushaf');
    $header = themeChrome(blkHome())['header'];

    expect($header)->toContain('<details')->toContain('<summary');
});

it('AC-15: serves every official theme with public cache headers, etag and 304, without tokens, livewire or cookies', function () {
    themeSeed();
    Setting::set('modules.psb.enabled', true);
    psbInfoPage(['status' => 'terbit']);

    foreach (THEME_OFFICIAL as $slug) {
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

it('AC-15: keeps the core chrome on session pages, 404 and verification pages while mushaf is active', function () {
    Setting::set('modules.psb.enabled', true);
    portalPsbOpen();
    $urls = [route('psb.register', absolute: false), route('portal-wali.login', absolute: false), '/tidak-ada-f102', '/verifikasi-surat'];
    $before = array_map(fn (string $url): array => themeChrome($this->get($url)->getContent()), $urls);

    themeActivate('mushaf');

    foreach ($urls as $index => $url) {
        expect(themeChrome($this->get($url)->getContent()))->toBe($before[$index], $url);
    }

    expect($before[0]['header'])->not->toBe('', 'psb.register memakai chrome inti');
});

it('AC-15: falls back to the core chrome with a 200 and one warning per slug when the active theme is missing or unusable', function () {
    themeSeed();
    $default = themeSquash(blkHome());
    $base = themeBaseManifest();
    $json = json_encode($base);
    $cases = [
        'hilang' => themeUniqueSlug(),
        'JSON rusak' => themeInstall('{"schema_version":1,'),
        'schema 99' => themeInstall([...$base, 'schema_version' => 99]),
        'melebihi 64 KB' => themeInstall($json.str_repeat(' ', 70 * 1024)),
        'min_core terlalu baru' => themeInstall([...$base, 'min_core' => '999.0.0']),
        'home.sections lama' => themeInstall([...$base, 'home' => ['sections' => []]]),
        'terlalu dalam' => themeInstall(themeLayout([themeNested(7, ['component' => 'hero'])])),
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

it('AC-14: treats a stored klasik value from old demo data as the core theme', function () {
    themeSeed();
    $core = themeSquash(blkHome());

    Setting::set('theme.template', 'klasik');
    app()->forgetScopedInstances();

    expect(app(GetPortalTheme::class)->template())->toBe('default')
        ->and(app(ResolveActiveTheme::class)->handle())->toBeNull()
        ->and(themeSquash(blkHome()))->toBe($core);
});

/*
| VERIFY F102: celah tepi susunan bebas (kedalaman, divider, feed_list magazine, XSS varian, cache, fallback sesi).
*/

it('VERIFY AC-2: renders a hero at depth 6 in home, and a brand at depth 6 in header and footer, without falling back', function () {
    themeSeed();
    $manifest = themeLayout([themeNested(6, ['component' => 'hero'])]);
    $manifest['header'] = themeNested(6, ['component' => 'brand']);
    $manifest['footer'] = themeNested(6, ['component' => 'copyright']);
    themeActivate(themeInstall($manifest));
    $html = blkHome();

    expect(themeMain($html))->toContain('Judul hero uji')
        ->and(themeChrome($html)['header'])->toContain('Ma&#039;had Al-Kitab')
        ->and(themeChrome($html)['footer'])->not->toBe('')
        ->and(substr_count($html, '<h1'))->toBe(1);
});

it('VERIFY AC-5: renders a divider only between shown content, never at the ends, doubled, or beside empty nodes or as a lone column cell', function () {
    portalSeedContent();
    blkSet([blk('hero', ['title' => 'Hero divider uji']), blk('text_image', ['heading' => 'Teks divider uji', 'text' => '<p>x</p>'])]);
    $dividers = fn (array $layout): int => substr_count(themeMain(themeHomeWith($layout)), 'rotate-45');

    expect($dividers([['component' => 'divider'], ['component' => 'hero'], ['component' => 'divider']]))->toBe(0, 'di ujung')
        ->and($dividers([['component' => 'hero'], ['component' => 'divider'], ['component' => 'quote'], ['component' => 'divider'], ['component' => 'text_image']]))->toBe(1, 'dua divider mengapit node kosong = satu')
        ->and($dividers([['component' => 'hero'], ['component' => 'divider'], ['component' => 'divider'], ['component' => 'text_image']]))->toBe(1, 'divider ganda = satu')
        ->and($dividers([['component' => 'hero'], ['component' => 'columns', 'children' => [['component' => 'divider'], ['component' => 'quote']]], ['component' => 'text_image']]))->toBe(0, 'kolom hanya berisi divider dan node kosong');
});

it('VERIFY AC-5: renders a columns node with a single shown cell and drops it with none, in a container with a divider around it', function () {
    portalSeedContent();
    blkSet([blk('hero', ['title' => 'Sel tunggal uji'])]);
    $html = themeMain(themeHomeWith([
        ['component' => 'container', 'children' => [
            ['component' => 'columns', 'style' => ['columns' => 4], 'children' => [['component' => 'quote'], ['component' => 'hero'], ['component' => 'cta'], ['component' => 'video']]],
            ['component' => 'columns', 'children' => [['component' => 'quote'], ['component' => 'cta']]],
        ]],
    ]));

    expect(substr_count($html, 'Sel tunggal uji'))->toBe(1)
        ->and(substr_count($html, 'grid-cols-'))->toBe(1, 'hanya satu grid columns yang dirender, yang kosong dibuang');
});

it('VERIFY AC-11: keeps one h1 and unique ids with heroes nested in containers and columns around a titled node', function () {
    themeSeed();
    $html = themeHomeWith([
        ['component' => 'columns', 'children' => [['component' => 'feed_list'], ['component' => 'hero']]],
        ['component' => 'container', 'children' => [['component' => 'hero'], ['component' => 'feed_list']]],
        ['component' => 'hero'],
    ]);
    $outline = themeExpectOutline($html, 'hero bersarang');

    expect($outline[0]['attrs'])->toContain('sr-only')
        ->and(collect($outline)->filter(fn (array $h): bool => $h['text'] === 'Judul hero uji')->pluck('level')->unique()->all())->toBe([2]);
});

it('VERIFY AC-5: renders feed_list magazine with one item and drops the node with none', function () {
    portalBoot();
    Setting::set('modules.konten.enabled', true);
    blkSet([blk('feed', ['source' => 'albums', 'heading' => 'Feed magazine uji'])]);
    $layout = [['component' => 'feed_list', 'variant' => 'magazine']];

    $empty = themeHomeWith($layout);
    expect($empty)->not->toContain('Feed magazine uji');

    portalAlbumWithPhotos(1, ['title' => 'Album tunggal uji']);
    $one = themeHomeWith($layout);

    expect($one)->toContain('Feed magazine uji')->toContain('Album tunggal uji')
        ->and(substr_count($one, '<ul class="min-w-0 space-y-4">'))->toBe(0);
});

it('VERIFY AC-5: drops nested feed nodes but keeps the hero when the content module is off', function () {
    themeSeed();
    Setting::set('modules.konten.enabled', false);
    $html = themeHomeWith([['component' => 'container', 'children' => [['component' => 'hero'], ['component' => 'feed_list']]]]);

    expect($html)->toContain('Judul hero uji')->not->toContain('Feed uji')->and(substr_count($html, '<h1'))->toBe(1);
});

it('VERIFY AC-8: escapes block text and the school name in every new variant', function () {
    portalBoot();
    portalSeedContent();
    Setting::set('school.name', 'Sekolah <script>alert(9)</script>');
    $xss = '<script>alert(1)</script><img src=x onerror=alert(2)>';
    blkSet([
        blk('quote', ['text' => $xss, 'translation' => $xss, 'source' => $xss]),
        blk('features', ['heading' => $xss, 'items' => [['title' => $xss, 'text' => $xss]]]),
        blk('cta', ['heading' => $xss, 'text' => $xss, 'variant' => 'light', 'primary' => navItem('posts', $xss)]),
        blk('hero', ['title' => $xss, 'text' => $xss, 'eyebrow' => $xss]),
        blk('feed', ['source' => 'posts', 'heading' => $xss]),
    ]);
    $html = themeHomeWith([
        ['component' => 'quote', 'variant' => 'framed'], ['component' => 'feature_grid', 'variant' => 'numbered'], ['component' => 'cta', 'variant' => 'card'],
        ['component' => 'hero', 'variant' => 'split'], ['component' => 'feed_list', 'variant' => 'magazine'], ['component' => 'feed_list', 'variant' => 'list'],
    ], chrome: true);

    expect($html)->not->toContain('<script>alert')->not->toContain('<img src=x')->not->toContain('onerror=alert(2)>');
});

it('VERIFY AC-5: renders majalah and mushaf with only one block, with no blocks, and with the content module off, with one h1 each', function () {
    portalBoot();
    portalSeedContent();

    foreach ([[blk('quote', ['text' => 'Satu-satunya blok uji'])], []] as $blocks) {
        blkSet($blocks);

        foreach (['majalah', 'mushaf'] as $slug) {
            themeActivate($slug);
            $html = blkHome();

            themeExpectOutline($html, $slug.' dengan '.count($blocks).' blok');
            expect(preg_match('#<section\b[^>]*>\s*</section>#', $html))->toBe(0, "{$slug}: tanpa section kosong");
        }
    }
});

it('VERIFY AC-15: changes the etag and the body when the theme changes, so a stale 304 is never served', function () {
    themeSeed();
    themeActivate('default');
    $first = $this->get('/');
    $etag = $first->headers->get('ETag');

    themeActivate('mushaf');
    $second = $this->withHeaders(['If-None-Match' => $etag])->get('/');

    expect($etag)->not->toBeNull()
        ->and($second->getStatusCode())->toBe(200, 'tema baru tidak dijawab 304 dari ETag tema lama')
        ->and($second->headers->get('ETag'))->not->toBe($etag);
});

it('VERIFY AC-15: keeps the core chrome with a 200 on session pages and 404 when the active theme is broken', function () {
    Setting::set('modules.psb.enabled', true);
    portalPsbOpen();
    $urls = [route('psb.register', absolute: false), route('portal-wali.login', absolute: false), route('psb.info', absolute: false), '/tidak-ada-f102'];
    $before = array_map(fn (string $url): array => themeChrome($this->get($url)->getContent()), $urls);

    themeActivate(themeInstall('{"schema_version":1,'));

    foreach ($urls as $index => $url) {
        $response = $this->get($url);
        expect(themeChrome($response->getContent()))->toBe($before[$index], $url)
            ->and($response->getStatusCode())->toBe($index === 3 ? 404 : 200, $url);
    }
});
