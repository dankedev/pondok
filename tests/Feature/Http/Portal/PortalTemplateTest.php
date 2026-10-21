<?php

use App\Actions\Konten\UpdatePortalTheme;
use App\Models\Setting;
use Database\Seeders\RolePermissionSeeder;

/*
| F072 AC-4 (HTML), AC-6 sampai AC-11. Preset beranda = `resources/views/themes/<kunci>/blocks/<tipe>.blade.php`; `theme.template`
| memilih preset (`default` atau `centered`), blok tanpa override jatuh ke preset default. Isian `portal.home_blocks` sama untuk semua preset.
*/

beforeEach(function () {
    portalBoot();
});

function tplUse(?string $template): void
{
    Setting::set('theme.template', $template);
    app()->forgetScopedInstances();
}

function tplHeroBlock(): array
{
    return blk('hero', ['title' => 'Judul hero uji', 'text' => 'Teks hero uji', 'image' => 'portal/blocks/hero.jpg', 'image_alt' => 'Gedung utama uji']);
}

/**
 * Isian berisi ketujuh tipe ditambah satu blok tersembunyi.
 *
 * @return list<array<string, mixed>>
 */
function tplAllBlocks(): array
{
    $album = kontenAlbum(['title' => 'Album uji template']);
    $video = kontenVideo(['title' => 'Video uji template']);

    return [
        blk('hero', ['eyebrow' => 'Label hero uji', 'title' => 'Judul hero uji', 'text' => 'Teks hero uji', 'image' => 'portal/blocks/hero.jpg', 'image_alt' => 'Gedung utama uji', 'primary' => navItem('posts', 'Tombol hero uji')]),
        blk('features', ['heading' => 'Keunggulan uji', 'items' => [['title' => 'Satu uji', 'text' => 'Deskripsi satu uji'], ['title' => 'Dua uji', 'text' => 'Deskripsi dua uji']]]),
        blk('feed', ['source' => 'albums', 'heading' => 'Feed uji']),
        blk('text_image', ['heading' => 'Tentang uji', 'text' => '<p>Isi tentang uji.</p>', 'image' => 'portal/blocks/tentang.jpg', 'image_alt' => 'Foto tentang uji', 'button' => navItem('posts', 'Tombol teks uji')]),
        blk('quote', ['text' => 'Teks kutipan uji', 'source' => 'Sumber uji']),
        blk('video', ['heading' => 'Video seksi uji', 'video_id' => $video->id]),
        blk('cta', ['heading' => 'Ajakan uji', 'variant' => 'light', 'primary' => navItem('posts', 'Tombol ajakan uji')]),
        blk('quote', ['text' => 'Kutipan tersembunyi uji'], visible: false),
    ];
}

function tplFindSection(string $html, string $needle): string
{
    preg_match_all('#<section\b.*?</section>#is', $html, $matches);

    foreach ($matches[0] as $section) {
        if (str_contains($section, $needle)) {
            return $section;
        }
    }

    return '';
}

function tplSection(string $html, string $tag): string
{
    preg_match('#<'.$tag.'\b.*?</'.$tag.'>#is', $html, $match);

    return $match[0] ?? '';
}

/**
 * Ringkasan isi yang harus sama di semua preset.
 *
 * @return array<string, mixed>
 */
function tplContentSummary(string $html): array
{
    preg_match_all('#<section\b[^>]*>#i', $html, $sections);
    preg_match_all('#aria-labelledby="([^"]*)"#', implode('', $sections[0]), $labels);
    preg_match_all('#alt="([^"]*)"#', $html, $alts);
    preg_match_all('#<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is', implode('', array_map(fn (string $section): string => $section, preg_match_all('#<section\b.*?</section>#is', $html, $all) ? $all[0] : [])), $links);

    return [
        'h1' => substr_count($html, '<h1'),
        'sections' => count($sections[0]),
        'labels' => $labels[1],
        'alts' => $alts[1],
        'links' => array_map(fn (string $href, string $label): string => $href.'|'.trim(strip_tags($label)), $links[1], $links[2]),
    ];
}

it('AC-4: renders the default markup when the stored template is corrupted', function () {
    blkSet([tplHeroBlock()]);

    foreach (['../portal/home', "centered\n", ['centered'], 'tidak-ada'] as $value) {
        tplUse(null);
        Setting::set('theme.template', $value);
        app()->forgetScopedInstances();

        expect(blkHome())->toContain('lg:grid-cols-2')->and(blkHome())->toContain('Judul hero uji');
    }
});

it('AC-6: renders the default hero as two columns with the image beside the text', function () {
    blkSet([tplHeroBlock()]);

    $hero = tplFindSection(blkHome(), 'Judul hero uji');

    expect($hero)->toContain('lg:grid-cols-2')->toContain('alt="Gedung utama uji"');
});

it('AC-7: the centered preset lays out the hero and features differently from default', function () {
    blkSet([tplHeroBlock(), blk('features', ['heading' => 'Keunggulan uji', 'items' => [['title' => 'Satu uji', 'text' => 'Deskripsi satu uji']]])]);

    $default = blkHome();

    tplUse('centered');
    $centered = blkHome();
    $hero = tplFindSection($centered, 'Judul hero uji');
    $features = tplFindSection($centered, 'Keunggulan uji');

    expect(tplFindSection($default, 'Keunggulan uji'))->toContain('shadow-card')
        ->and($hero)->not->toContain('lg:grid-cols-2')->toContain('text-center')->toContain('alt="Gedung utama uji"')
        ->and($features)->not->toContain('shadow-card')->toContain('divide-y');
});

it('AC-8: every preset renders the same blocks, order, headings, buttons and alt texts from the same data', function () {
    blkSet(tplAllBlocks());

    $reference = blkHome();
    $referenceSummary = tplContentSummary($reference);
    $needles = ['Judul hero uji', 'Keunggulan uji', 'Deskripsi dua uji', 'Tentang uji', 'Teks kutipan uji', 'Video seksi uji', 'Ajakan uji'];
    $referenceOrder = array_map(fn (string $needle): int|false => mb_strpos($reference, $needle), $needles);

    expect($referenceOrder)->not->toContain(false)
        ->and($reference)->not->toContain('Kutipan tersembunyi uji')
        ->and($referenceSummary['h1'])->toBe(1);

    foreach (['default', 'centered'] as $template) {
        tplUse($template);
        $html = blkHome();
        $order = array_map(fn (string $needle): int|false => mb_strpos($html, $needle), $needles);

        expect($order)->not->toContain(false, $template)
            ->and($order)->toBe(collect($order)->sort()->values()->all(), "{$template}: urutan blok")
            ->and($html)->not->toContain('Kutipan tersembunyi uji')
            ->and(tplContentSummary($html))->toBe($referenceSummary, "{$template}: ringkasan isi");
    }
});

it('AC-9: header and footer are identical in every preset', function () {
    blkSet(tplAllBlocks());

    $reference = blkHome();
    tplUse('centered');
    $centered = blkHome();

    expect(tplSection($reference, 'header'))->not->toBe('')
        ->and(tplSection($reference, 'footer'))->not->toBe('')
        ->and(tplSection($centered, 'header'))->toBe(tplSection($reference, 'header'))
        ->and(tplSection($centered, 'footer'))->toBe(tplSection($reference, 'footer'));
});

it('AC-10: switching the template never touches the saved home blocks and the text keeps showing', function () {
    blkSet([tplHeroBlock(), blk('quote', ['text' => 'Teks kutipan uji'])]);
    $raw = blkStored();
    $this->seed(RolePermissionSeeder::class);
    $admin = kontenUser('admin-konten');

    foreach (['centered', 'default', 'centered'] as $template) {
        app(UpdatePortalTheme::class)->handle(['template' => $template], $admin);
        app()->forgetScopedInstances();

        expect(blkStored())->toBe($raw, $template)
            ->and(profilVisibleText(blkHome()))->toContain('Judul hero uji')->toContain('Teks kutipan uji');
    }
});

it('AC-11: other portal pages do not change with the centered template', function () {
    Setting::set('modules.psb.enabled', true);
    navSet([navItem('home')], false);
    psbInfoPage(['status' => 'terbit']);

    $pages = [route('portal.posts.index'), route('psb.info')];
    $before = array_map(fn (string $url): string => test()->get($url)->assertOk()->getContent(), $pages);

    tplUse('centered');

    foreach ($pages as $index => $url) {
        $html = test()->get($url)->assertOk()->getContent();

        expect($html)->toBe($before[$index], $url)->not->toContain('themes.centered');
    }
});
