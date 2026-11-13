<?php

use App\Actions\Konten\ResolveActiveTheme;
use App\Actions\Konten\ThemeComponents;
use App\Models\Setting;

/*
| F105 AC-13 dan AC-15 (dokumen). Kontrak: `ThemeComponents::PARTS` (komponen => daftar nama bagian) adalah sumber tunggal kait markup. Hanya pada tema ber-css,
| tiap node tema mendapat `data-pt="<komponen>"`, `data-pt-variant="<varian>"` bila bervarian, dan `data-pt-part="<bagian>"` pada bagian bernama. Pada tema tanpa
| css tidak ada atribut `data-pt*` sama sekali dan tidak ada spasi sisa (keluaran sama dengan versi ber-css setelah atribut dibuang).
| `docs/tema-kontrak-markup.md` memuat setiap komponen, varian, dan bagian; `docs/rilis.md` memuat bagian "Menyusun, menguji, dan menandatangani paket tema ber-CSS".
*/

beforeEach(function () {
    portalBoot();
    portalSeedContent();
    themeSandbox();
    themeInstalledSandbox();
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
    ]);
});

/**
 * Manifest yang memuat setiap komponen; varian tiap komponen dipilih berputar menurut `$round`.
 *
 * @return array<string, mixed>
 */
function mcManifest(int $round, bool $blocksOnly = false): array
{
    $variant = fn (string $component): array => isset(ThemeComponents::VARIANTS[$component])
        ? ['variant' => ThemeComponents::VARIANTS[$component][$round % count(ThemeComponents::VARIANTS[$component])]]
        : [];
    $node = fn (string $component, array $extra = []): array => ['component' => $component, ...$variant($component), ...$extra];
    $top = ['style' => ['tone' => 'auto']];

    // `blocks` harus menjadi satu-satunya node di home.layout, jadi diuji di halaman tersendiri.
    $home = $blocksOnly ? [$node('blocks')] : [
        $node('hero', $top), $node('text_image', $top), $node('feature_grid', $top), $node('feed_list', $top), $node('quote', $top),
        $node('video', $top), $node('cta', $top), ['component' => 'divider'],
        ['component' => 'container', 'children' => [['component' => 'quote']]],
        ['component' => 'columns', 'style' => ['columns' => 2], 'children' => [['component' => 'quote'], ['component' => 'quote']]],
    ];

    return [
        'header' => ['component' => 'container', 'children' => [['component' => 'brand'], ['component' => 'nav_menu']]],
        'footer' => ['component' => 'container', 'children' => [['component' => 'footer_widgets'], ['component' => 'copyright']]],
        'home' => ['layout' => $home],
    ];
}

function mcRender(int $round, bool $withCss, bool $blocksOnly = false): string
{
    $pkg = $withCss
        ? themePkg(['manifest' => mcManifest($round, $blocksOnly)])
        : null;
    $slug = $pkg['slug'] ?? themeUniqueSlug();

    if ($pkg !== null) {
        themePkgPlace($pkg);
    } else {
        themeInstalledWrite($slug, [...themeBaseManifest(), ...mcManifest($round, $blocksOnly)]);
    }

    Setting::set('theme.template', $slug);
    app()->forgetScopedInstances();

    // Tema uji sungguh dipakai (bukan jatuh ke chrome inti), dengan atau tanpa css.
    $active = app(ResolveActiveTheme::class)->handle()['slug'] ?? null;
    $html = test()->get('/')->assertOk()->getContent();

    expect($active)->toBe($slug);

    return $html;
}

function mcAllComponents(): array
{
    return array_values(array_unique(array_merge(...array_values(ThemeComponents::SLOTS))));
}

/**
 * `ThemeComponents::SHARED_VARIANTS` (komponen bersama tanpa slot); kosong selama konstanta belum ada.
 *
 * @return array<string, list<string>>
 */
function mcSharedVariants(): array
{
    return defined(ThemeComponents::class.'::SHARED_VARIANTS') ? constant(ThemeComponents::class.'::SHARED_VARIANTS') : [];
}

/**
 * Daftar bagian F105 yang SELALU tercetak pada beranda lengkap (beku: nama tidak boleh berubah atau hilang).
 *
 * @return array<string, list<string>>
 */
function mcF105Parts(): array
{
    return [
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
}

it('F105 AC-13: declares the parts per component in ThemeComponents::PARTS for real components only', function () {
    expect(ThemeComponents::PARTS)->toBeArray()->not->toBeEmpty();

    foreach (ThemeComponents::PARTS as $component => $parts) {
        // F106: komponen bersama tanpa slot (`SHARED_VARIANTS`) juga sah; `button` boleh tanpa bagian.
        $shared = array_keys(mcSharedVariants());

        expect([...mcAllComponents(), ...$shared])->toContain($component)
            ->and($parts)->toBeArray();

        if (! in_array($component, $shared, true) || $parts !== []) {
            expect($parts)->not->toBeEmpty("{$component}: bagian tidak boleh kosong");
        }

        foreach ($parts as $part) {
            expect($part)->toMatch('/^[a-z][a-z0-9-]*$/', "{$component}: nama bagian");
        }
    }
});

it('F105 AC-13: puts data-pt, data-pt-variant and every data-pt-part on a css theme for every component and variant', function () {
    $variantsSeen = [];
    $pages = [[0, false], [1, false], [2, false], [0, true], [1, true]];
    $partsSeen = [];

    foreach ($pages as [$round, $blocksOnly]) {
        $html = mcRender($round, true, $blocksOnly);

        foreach (mcAllComponents() as $component) {
            if ($component === 'blocks' ? $blocksOnly : ! $blocksOnly) {
                expect($html)->toContain("data-pt=\"{$component}\"");
            }
        }

        foreach (ThemeComponents::VARIANTS as $component => $variants) {
            if (($component === 'blocks') !== $blocksOnly) {
                continue;
            }

            $variant = $variants[$round % count($variants)];
            $variantsSeen[$component][$variant] = true;

            expect($html)->toMatch('/<[^>]*data-pt="'.$component.'"[^>]*data-pt-variant="'.$variant.'"|<[^>]*data-pt-variant="'.$variant.'"[^>]*data-pt="'.$component.'"/', "{$component} varian {$variant}");
        }

        foreach (mcF105Parts() as $component => $parts) {
            foreach ($parts as $part) {
                if (str_contains($html, "data-pt-part=\"{$part}\"")) {
                    $partsSeen[$component][$part] = true;
                }
            }
        }
    }

    foreach (ThemeComponents::VARIANTS as $component => $variants) {
        expect(array_keys($variantsSeen[$component] ?? []))->toEqualCanonicalizing($variants, "{$component}: semua varian teruji");
    }

    // Komponen slot saja; bagian kartu/halaman/tombol/hero-baru dituntut di ThemeMarkupHooksTest. Yang diwajibkan di beranda lengkap: bagian F105.
    foreach (mcF105Parts() as $component => $parts) {
        expect(array_keys($partsSeen[$component] ?? []))->toEqualCanonicalizing($parts, "{$component}: semua bagian F105 muncul");
    }
});

it('F105 AC-13: adds no data-pt attribute and no stray space to a theme without css', function () {
    foreach ([[0, false], [1, false], [2, false], [1, true]] as [$round, $blocksOnly]) {
        $plain = mcRender($round, false, $blocksOnly);
        $themed = mcRender($round, true, $blocksOnly);
        $stripped = preg_replace('/ data-pt(?:-[a-z]+)*="[^"]*"/', '', (string) preg_replace('/\s*<link\b[^>]*\/tema\/[^>]*>/i', '', $themed));

        expect($plain)->not->toContain('data-pt')
            ->and($plain)->not->toMatch('/<[a-z][a-z0-9-]*\s{2,}>/i')
            ->and($themed)->toContain('data-pt=')
            ->and(preg_replace('/\s+/', ' ', (string) $stripped))->toBe(preg_replace('/\s+/', ' ', $plain), "putaran {$round}");
    }
});

it('F105 AC-13: documents every component, variant and part in docs/tema-kontrak-markup.md', function () {
    $path = base_path('docs/tema-kontrak-markup.md');

    expect(is_file($path))->toBeTrue('docs/tema-kontrak-markup.md belum ada');

    $doc = (string) file_get_contents($path);

    foreach (mcAllComponents() as $component) {
        expect($doc)->toContain($component);
    }

    foreach (ThemeComponents::VARIANTS as $component => $variants) {
        foreach ($variants as $variant) {
            expect($doc)->toContain($variant);
        }
    }

    foreach (ThemeComponents::PARTS as $component => $parts) {
        foreach ($parts as $part) {
            expect($doc)->toContain($part);
        }
    }
});

it('F106 AC-7: keeps every frozen F105 component-part pair in PARTS and prints it on a full-content css theme', function () {
    $seen = [];

    foreach ([[0, false], [1, false], [2, false], [0, true], [1, true]] as [$round, $blocksOnly]) {
        $html = mcRender($round, true, $blocksOnly);

        foreach (mcF105Parts() as $component => $parts) {
            foreach ($parts as $part) {
                if (str_contains($html, "data-pt-part=\"{$part}\"")) {
                    $seen[$component][$part] = true;
                }
            }
        }
    }

    foreach (mcF105Parts() as $component => $parts) {
        expect(ThemeComponents::PARTS)->toHaveKey($component);

        foreach ($parts as $part) {
            expect(in_array($part, ThemeComponents::PARTS[$component], true))->toBeTrue("F105 {$component}/{$part} hilang dari PARTS")
                ->and($seen[$component][$part] ?? false)->toBeTrue("F105 {$component}/{$part} tidak tercetak");
        }
    }
});

it('F105 AC-15: documents publishing a css theme in docs/rilis.md', function () {
    $rilis = (string) file_get_contents(base_path('docs/rilis.md'));

    expect($rilis)->toContain('Menyusun, menguji, dan menandatangani paket tema ber-CSS')
        ->and($rilis)->toContain('pondok:theme-sign')->toContain('--write-hashes');
});
