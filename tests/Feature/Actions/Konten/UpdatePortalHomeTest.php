<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| F045 AC-3, AC-4, AC-6. Kontrak: `UpdatePortalHome::handle(['home_blocks' => list<{type, visible, data}>], User)`.
| Galat: `ValidationException` pada `home_blocks` (daftar), `home_blocks.{i}.type`, atau `home_blocks.{i}.data.{field}`
| (bersarang: `...items.{j}.title`, `...primary.url`). Gambar blok: `data.image` (`UploadedFile` atau path string
| yang sudah tersimpan di `portal.home_blocks`), disimpan ke disk `public` di `portal/blocks/`.
| Item tombol tersimpan `{type, label, ...}` seperti navigasi F043.
*/

beforeEach(function () {
    kontenBoot();
});

it('AC-3: saves every block type normalized, in order, with every button destination', function () {
    $page = staticPage(['slug' => 'pengurus']);
    $category = kontenCategory('post', 'Pengumuman');
    $video = kontenVideo();

    blkSave([
        blk('hero', [
            'eyebrow' => '  Label  ', 'title' => ' Judul hero ', 'text' => 'Teks hero', 'image' => kontenImage('hero.jpg'), 'image_alt' => 'Gedung utama',
            'primary' => navItem('psb.register'), 'secondary' => navItem('url', 'Situs luar', ['url' => 'https://contoh.test/a']),
        ]),
        blk('text_image', [
            'heading' => 'Tentang', 'text' => '<p>Isi <strong>tebal</strong></p>', 'image' => kontenImage('t.jpg'), 'image_alt' => 'Foto kegiatan',
            'button' => navPage($page, 'Baca'),
        ]),
        blk('features', ['heading' => 'Keunggulan', 'items' => [['title' => 'Satu', 'text' => 'Deskripsi'], ['title' => 'Dua', 'text' => '']]]),
        blk('quote', ['text' => 'ٱلْحَمْدُ لِلَّهِ', 'translation' => 'Segala puji bagi Allah', 'source' => 'QS. Al-Fatihah: 2', 'rtl' => true]),
        blk('cta', ['eyebrow' => 'Gabung', 'heading' => 'Ajakan satu', 'text' => 'Teks ajakan', 'variant' => 'navy', 'primary' => navItem('category', null, ['category_id' => $category->id]), 'secondary' => navItem('home')]),
        blk('cta', ['heading' => 'Ajakan dua', 'variant' => 'gold', 'primary' => navItem('albums'), 'secondary' => navItem('videos')]),
        blk('cta', ['heading' => 'Ajakan tiga', 'variant' => 'light', 'primary' => navItem('psb.info'), 'secondary' => navItem('posts')]),
        blk('video', ['heading' => 'Profil', 'video_id' => $video->id], visible: false),
        blk('feed', ['source' => 'achievements', 'heading' => '']),
    ]);

    $stored = blkStored();
    [$hero, $textImage, $features, $quote, $cta1, $cta2, $cta3, $videoBlock, $feed] = $stored;

    expect(array_column($stored, 'type'))->toBe(['hero', 'text_image', 'features', 'quote', 'cta', 'cta', 'cta', 'video', 'feed'])
        ->and(array_column($stored, 'visible'))->toBe([true, true, true, true, true, true, true, false, true])
        ->and($hero['data'])->toMatchArray(['eyebrow' => 'Label', 'title' => 'Judul hero', 'text' => 'Teks hero', 'image_alt' => 'Gedung utama'])
        ->and($hero['data']['image'])->toStartWith('portal/blocks/')
        ->and(Storage::disk('public')->exists($hero['data']['image']))->toBeTrue()
        ->and($hero['data']['primary'])->toMatchArray(['type' => 'psb.register'])
        ->and($hero['data']['secondary'])->toMatchArray(['type' => 'url', 'label' => 'Situs luar', 'url' => 'https://contoh.test/a'])
        ->and($textImage['data'])->toMatchArray(['heading' => 'Tentang', 'text' => '<p>Isi <strong>tebal</strong></p>'])
        ->and(Storage::disk('public')->exists($textImage['data']['image']))->toBeTrue()
        ->and($textImage['data']['button'])->toMatchArray(['type' => 'page', 'page_id' => $page->id, 'label' => 'Baca'])
        ->and($features['data']['items'][0])->toMatchArray(['title' => 'Satu', 'text' => 'Deskripsi'])
        ->and($features['data']['items'][1])->toMatchArray(['title' => 'Dua', 'text' => null])
        ->and($quote['data'])->toMatchArray(['text' => 'ٱلْحَمْدُ لِلَّهِ', 'rtl' => true, 'source' => 'QS. Al-Fatihah: 2'])
        ->and($cta1['data'])->toMatchArray(['variant' => 'navy', 'heading' => 'Ajakan satu'])
        ->and($cta1['data']['primary'])->toMatchArray(['type' => 'category', 'category_id' => $category->id])
        ->and($cta1['data']['secondary'])->toMatchArray(['type' => 'home'])
        ->and([$cta2['data']['primary']['type'], $cta2['data']['secondary']['type'], $cta3['data']['primary']['type'], $cta3['data']['secondary']['type']])->toBe(['albums', 'videos', 'psb.info', 'posts'])
        ->and($videoBlock['data']['video_id'])->toBe($video->id)
        ->and($feed['data'])->toMatchArray(['source' => 'achievements'])
        ->and($feed['data']['heading'] ?? null)->toBeNull()
        ->and(kontenActivities()->where('description', 'portal_home_updated'))->toHaveCount(1);
});

it('AC-3: saving the stored blocks again changes nothing and writes no log; a real change logs once more', function () {
    blkSave([blk('hero', ['title' => 'Judul', 'image' => kontenImage(), 'image_alt' => 'Alt']), blk('cta', ['heading' => 'Ajak', 'variant' => 'light'])]);
    $stored = blkStored();

    blkSave($stored);

    expect(blkStored())->toEqual($stored)
        ->and(kontenActivities()->where('description', 'portal_home_updated'))->toHaveCount(1);

    $stored[1]['data']['heading'] = 'Ajak lagi';
    blkSave($stored);

    expect(kontenActivities()->where('description', 'portal_home_updated'))->toHaveCount(2)
        ->and(kontenFiles())->toHaveCount(1);
});

it('AC-3: accepts the limits exactly and an empty list clears the home', function () {
    $album = kontenAlbum();
    $manyFeatures = array_map(fn (int $i): array => ['title' => "K{$i}"], range(1, 8));
    $blocks = [
        blk('hero', ['title' => 'H', 'album_id' => $album->id, 'eyebrow' => str_repeat('e', 100), 'text' => str_repeat('t', 300)]),
        blk('features', ['items' => $manyFeatures]),
        blk('quote', ['text' => str_repeat('q', 1000), 'translation' => str_repeat('t', 1000), 'source' => str_repeat('s', 100)]),
        blk('feed', ['source' => 'posts']),
        blk('feed', ['source' => 'videos']),
        ...array_fill(0, 15, blk('cta', ['heading' => str_repeat('h', 100), 'variant' => 'light'])),
    ];

    expect($blocks)->toHaveCount(20);

    blkSave($blocks);

    expect(blkStored())->toHaveCount(20);

    blkSave([]);

    expect(blkStored())->toBe([]);
});

it('AC-4: rejects invalid blocks with an error on the exact nested field and changes nothing', function () {
    $album = kontenAlbum();
    blkSave([blk('cta', ['heading' => 'Lama', 'variant' => 'light'])]);
    $cta = fn (array $data): array => [blk('cta', ['heading' => 'Ajak', 'variant' => 'light', ...$data])];
    $withUnsafe = fn (string $url): array => $cta(['primary' => navItem('url', 'Tombol', ['url' => $url])]);
    $features = fn (int $n): array => [blk('features', ['items' => array_map(fn (int $i): array => ['title' => "K{$i}"], $n > 0 ? range(1, $n) : [])])];

    $cases = [
        'unknown type' => [[blk('banner', ['title' => 'x'])], 'home_blocks.0.type'],
        'second hero' => [[blk('hero', ['title' => 'a']), blk('hero', ['title' => 'b'])], 'home_blocks.1.type'],
        '21 blocks' => [array_fill(0, 21, blk('cta', ['heading' => 'x', 'variant' => 'light'])), 'home_blocks'],
        'features 0' => [$features(0), 'home_blocks.0.data.items'],
        'features 9' => [$features(9), 'home_blocks.0.data.items'],
        'feature title empty' => [[blk('features', ['items' => [['title' => '']]])], 'home_blocks.0.data.items.0.title'],
        'feature text 301' => [[blk('features', ['items' => [['title' => 'a', 'text' => str_repeat('a', 301)]]])], 'home_blocks.0.data.items.0.text'],
        'cta heading empty' => [$cta(['heading' => '']), 'home_blocks.0.data.heading'],
        'cta heading 101' => [$cta(['heading' => str_repeat('a', 101)]), 'home_blocks.0.data.heading'],
        'cta variant unknown' => [$cta(['variant' => 'merah']), 'home_blocks.0.data.variant'],
        'quote text empty' => [[blk('quote', ['text' => ''])], 'home_blocks.0.data.text'],
        'quote text 1001' => [[blk('quote', ['text' => str_repeat('a', 1001)])], 'home_blocks.0.data.text'],
        'hero title 151' => [[blk('hero', ['title' => str_repeat('a', 151)])], 'home_blocks.0.data.title'],
        'hero eyebrow 101' => [[blk('hero', ['eyebrow' => str_repeat('a', 101)])], 'home_blocks.0.data.eyebrow'],
        'hero text 301' => [[blk('hero', ['text' => str_repeat('a', 301)])], 'home_blocks.0.data.text'],
        'text_image text 2001' => [[blk('text_image', ['text' => '<p>'.str_repeat('a', 2001).'</p>'])], 'home_blocks.0.data.text'],
        'feed source unknown' => [[blk('feed', ['source' => 'lain'])], 'home_blocks.0.data.source'],
        'video missing' => [[blk('video', ['video_id' => 99999])], 'home_blocks.0.data.video_id'],
        'video not given' => [[blk('video', [])], 'home_blocks.0.data.video_id'],
        'hero album missing' => [[blk('hero', ['title' => 'a', 'album_id' => 99999])], 'home_blocks.0.data.album_id'],
        'button page missing' => [$cta(['primary' => navItem('page', null, ['page_id' => 99999])]), 'home_blocks.0.data.primary.page_id'],
        'button category missing' => [$cta(['secondary' => navItem('category', null, ['category_id' => 99999])]), 'home_blocks.0.data.secondary.category_id'],
        'button type unknown' => [$cta(['primary' => navItem('group', 'x')]), 'home_blocks.0.data.primary.type'],
        'button url empty' => [$cta(['primary' => navItem('url', 'x', ['url' => ''])]), 'home_blocks.0.data.primary.url'],
        'button label 31' => [$cta(['primary' => navItem('home', str_repeat('a', 31))]), 'home_blocks.0.data.primary.label'],
        'url javascript' => [$withUnsafe('javascript:alert(1)'), 'home_blocks.0.data.primary.url'],
        'url mixed case with space' => [$withUnsafe(' JavaScript:alert(1)'), 'home_blocks.0.data.primary.url'],
        'url protocol relative' => [$withUnsafe('//evil.test/x'), 'home_blocks.0.data.primary.url'],
        'url backslash host' => [$withUnsafe('/\\evil.test'), 'home_blocks.0.data.primary.url'],
        'url data' => [$withUnsafe('data:text/html,x'), 'home_blocks.0.data.primary.url'],
        'image and album' => [[blk('hero', ['title' => 'a', 'album_id' => $album->id, 'image' => kontenImage(), 'image_alt' => 'a'])], ['home_blocks.0.data.image', 'home_blocks.0.data.album_id']],
        'image without alt' => [[blk('hero', ['title' => 'a', 'image' => kontenImage()])], 'home_blocks.0.data.image_alt'],
        'foreign image path' => [[blk('hero', ['title' => 'a', 'image' => 'school/logo.png', 'image_alt' => 'a'])], 'home_blocks.0.data.image'],
        'pdf upload' => [[blk('hero', ['title' => 'a', 'image' => kontenPdf(), 'image_alt' => 'a'])], 'home_blocks.0.data.image'],
        'svg upload' => [[blk('hero', ['title' => 'a', 'image' => UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'), 'image_alt' => 'a'])], 'home_blocks.0.data.image'],
        'upload over 2 MB' => [[blk('hero', ['title' => 'a', 'image' => kontenImage('besar.jpg', 2049), 'image_alt' => 'a'])], 'home_blocks.0.data.image'],
        'second block is the invalid one' => [[blk('cta', ['heading' => 'Sah', 'variant' => 'light']), blk('quote', ['text' => ''])], 'home_blocks.1.data.text'],
    ];

    foreach ($cases as $label => [$blocks, $field]) {
        blkAssertRejected($blocks, $field, $label);
    }

    expect(blkStored()[0]['data']['heading'])->toBe('Lama')->and(kontenFiles())->toBe([]);
});

it('AC-4: rejects a malformed payload instead of failing with an exception', function () {
    blkAssertRejected([['type' => 'cta', 'visible' => true, 'data' => 'bukan array']], ['home_blocks.0.data', 'home_blocks.0.data.heading'], 'data bukan array');
    blkAssertRejected(['teks'], ['home_blocks.0', 'home_blocks.0.type'], 'blok bukan array');
});

it('AC-4: accepts an image path that is already stored in the home and rejects it once it is not', function () {
    blkSave([blk('hero', ['title' => 'H', 'image' => kontenImage(), 'image_alt' => 'Alt'])]);
    $path = blkStored()[0]['data']['image'];

    blkSave([blk('hero', ['title' => 'H baru', 'image' => $path, 'image_alt' => 'Alt'])]);

    expect(blkStored()[0]['data'])->toMatchArray(['title' => 'H baru', 'image' => $path]);

    blkSave([blk('cta', ['heading' => 'Ganti', 'variant' => 'light'])]);

    blkAssertRejected([blk('hero', ['title' => 'H', 'image' => $path, 'image_alt' => 'Alt'])], 'home_blocks.0.data.image', 'path lama yang sudah dilepas');
});

it('AC-5: stores limited text with only p, br, strong and safe links', function () {
    blkSave([blk('text_image', [
        'heading' => 'Tentang',
        'text' => '<p onclick="x()" class="c" style="color:red">Halo <script>alert(1)</script><strong>tebal</strong><br><h1>Besar</h1> '
            .'<a href="javascript:alert(1)">buruk</a> <a href="//evil.test">luar</a> <a href="https://baik.test/a" target="_blank" onclick="y()">baik</a></p>',
    ])]);

    $text = blkStored()[0]['data']['text'];

    expect($text)->toContain('<strong>tebal</strong>')->toContain('Halo')->toContain('href="https://baik.test/a"')->toContain('rel="noopener noreferrer"')
        ->not->toContain('<script')->not->toContain('onclick')->not->toContain('style=')->not->toContain('class=')
        ->not->toContain('<h1')->not->toContain('javascript:')->not->toContain('//evil.test');
});

it('AC-6: replaces and removes block images, deleting the old file only after a successful save', function () {
    blkSave([blk('hero', ['title' => 'H', 'image' => kontenImage('a.jpg'), 'image_alt' => 'A'])]);
    $first = blkStored()[0]['data']['image'];

    blkSave([blk('hero', ['title' => 'H', 'image' => kontenImage('b.jpg'), 'image_alt' => 'B'])]);
    $second = blkStored()[0]['data']['image'];

    expect($second)->not->toBe($first)->and(kontenFiles())->toBe([$second]);

    blkAssertThrows(fn () => blkSave([blk('hero', ['title' => 'H', 'image' => kontenImage('c.jpg'), 'image_alt' => 'C']), blk('quote', ['text' => ''])]));

    expect(kontenFiles())->toBe([$second])->and(blkStored()[0]['data']['image'])->toBe($second);

    blkSave([blk('hero', ['title' => 'H', 'image' => kontenImage('d.jpg'), 'image_alt' => 'D']), blk('text_image', ['heading' => 'T', 'image' => kontenImage('e.jpg'), 'image_alt' => 'E'])]);

    expect(kontenFiles())->toHaveCount(2)->and(Storage::disk('public')->exists($second))->toBeFalse();

    blkSave([blk('cta', ['heading' => 'Tanpa gambar', 'variant' => 'light'])]);

    expect(kontenFiles())->toBe([])->and(blkStored())->toHaveCount(1);
});

/**
 * Callback harus melempar ValidationException.
 */
function blkAssertThrows(Closure $callback): void
{
    try {
        $callback();
    } catch (ValidationException) {
        expect(true)->toBeTrue();

        return;
    }

    test()->fail('ValidationException diharapkan, tetapi tidak dilempar.');
}
