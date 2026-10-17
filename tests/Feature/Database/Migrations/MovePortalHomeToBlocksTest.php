<?php

use App\Models\StaticPage;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

/*
| F045 AC-1 dan AC-2. Kontrak: migrasi `2026_10_20_000000_move_portal_home_to_blocks.php` (anonymous class,
| `up()` dan `down()`), ditulis dengan `DB::table` (JSON di-decode sendiri). Hasil: `portal.home_blocks` berisi
| daftar `{type, visible, data}`; Item tombol disimpan `{type, label, ...}` seperti navigasi F043.
*/

beforeEach(function () {
    portalBoot();
    $this->seed(RolePermissionSeeder::class);
    $this->legacyKeys = [
        'portal.hero_greeting', 'portal.hero_title', 'portal.hero_subtitle', 'portal.highlights_heading', 'portal.highlights',
        'portal.hero_album_id', 'portal.profile_video_id', 'portal.psb_video_id', 'portal.archived_footer_quote',
    ];
});

it('AC-1: migrates the legacy home into blocks in display order and removes the old keys', function () {
    $album = portalAlbumWithPhotos(2, ['title' => 'Fasilitas']);
    $profileVideo = kontenVideo(['title' => 'Video Profil']);
    $psbVideo = kontenVideo(['title' => 'Video PSB']);
    $profil = StaticPage::findBySlug('profil');
    $profil->update(['title' => 'Profil Pondok', 'body' => '<p>Pondok   kami <strong>berdiri</strong> sejak 1990 &amp; terus berkembang.</p><script>x</script>']);
    profilRawSet('portal.hero_greeting', 'Sambutan uji');
    profilRawSet('portal.hero_title', 'Judul uji');
    profilRawSet('portal.hero_subtitle', 'Subjudul uji');
    profilRawSet('portal.highlights_heading', 'Mengapa memilih kami');
    profilRawSet('portal.highlights', [['title' => 'Satu', 'description' => 'Deskripsi satu'], ['title' => 'Dua', 'description' => null]]);
    profilRawSet('portal.hero_album_id', $album->id);
    profilRawSet('portal.profile_video_id', $profileVideo->id);
    profilRawSet('portal.psb_video_id', $psbVideo->id);
    profilRawSet('portal.archived_footer_quote', ['arabic' => 'نص عربي للاختبار', 'translation' => 'Terjemah uji', 'source' => 'Sumber uji']);

    blkMigration()->up();

    $blocks = blkStored();

    expect(array_column($blocks, 'type'))->toBe(['hero', 'features', 'video', 'video', 'text_image', 'feed', 'feed', 'feed', 'feed', 'quote'])
        ->and(array_unique(array_column($blocks, 'visible')))->toBe([true]);

    [$hero, $features, $video1, $video2, $profile, $news, $achievements, $videos, $albums, $quote] = $blocks;

    expect($hero['data'])->toMatchArray(['eyebrow' => 'Sambutan uji', 'title' => 'Judul uji', 'text' => 'Subjudul uji', 'album_id' => $album->id])
        ->and($hero['data']['primary'])->toMatchArray(['type' => 'psb.register'])
        ->and($hero['data']['secondary'])->toMatchArray(['type' => 'posts'])
        ->and($features['data']['heading'])->toBe('Mengapa memilih kami')
        ->and($features['data']['items'])->toHaveCount(2)
        ->and($features['data']['items'][0])->toMatchArray(['title' => 'Satu', 'text' => 'Deskripsi satu'])
        ->and($features['data']['items'][1])->toMatchArray(['title' => 'Dua', 'text' => null])
        ->and([$video1['data']['video_id'], $video2['data']['video_id']])->toBe([$profileVideo->id, $psbVideo->id])
        ->and($profile['data']['heading'])->toBe('Profil Pondok')
        ->and($profile['data']['text'])->toBe('<p>Pondok kami berdiri sejak 1990 &amp; terus berkembang.</p>')
        ->and($profile['data']['button'])->toMatchArray(['type' => 'page', 'page_id' => $profil->id])
        ->and(array_column(array_column([$news, $achievements, $videos, $albums], 'data'), 'source'))->toBe(['posts', 'achievements', 'videos', 'albums'])
        ->and($news['data']['heading'] ?? null)->toBeNull()
        ->and($quote['data'])->toMatchArray(['text' => 'نص عربي للاختبار', 'translation' => 'Terjemah uji', 'source' => 'Sumber uji', 'rtl' => true]);

    foreach ($this->legacyKeys as $key) {
        expect(profilRawHas($key))->toBeFalse($key);
    }

    // Hasil migrasi lolos validasi tanpa diubah.
    $before = $blocks;
    blkSave($blocks);

    expect(blkStored())->toEqual($before)
        ->and(kontenActivities()->where('description', 'portal_home_updated'))->toHaveCount(0);
});

it('AC-1: keeps the values at the old limits whole and skips the references that no longer exist', function () {
    $video = kontenVideo(['title' => 'Video Profil']);
    $album = kontenAlbum();
    profilRawSet('portal.hero_greeting', str_repeat('g', 100));
    profilRawSet('portal.highlights_heading', str_repeat('h', 100));
    profilRawSet('portal.highlights', [['title' => str_repeat('t', 80), 'description' => str_repeat('d', 300)]]);
    profilRawSet('portal.archived_footer_quote', ['arabic' => str_repeat('ا', 1000), 'translation' => str_repeat('t', 1000), 'source' => str_repeat('s', 100)]);
    profilRawSet('portal.profile_video_id', $video->id);
    profilRawSet('portal.psb_video_id', 99999);
    profilRawSet('portal.hero_album_id', $album->id);
    $album->delete();
    StaticPage::findBySlug('profil')->update(['body' => '']);

    blkMigration()->up();

    $blocks = blkStored();
    $byType = collect($blocks)->groupBy('type');

    expect($blocks[0]['data']['eyebrow'])->toBe(str_repeat('g', 100))
        ->and($blocks[0]['data']['album_id'] ?? null)->toBeNull()
        ->and($byType['features'][0]['data']['heading'])->toBe(str_repeat('h', 100))
        ->and($byType['features'][0]['data']['items'][0]['text'])->toBe(str_repeat('d', 300))
        ->and($byType['video'])->toHaveCount(1)
        ->and($byType['video'][0]['data']['video_id'])->toBe($video->id)
        ->and($byType->has('text_image'))->toBeFalse()
        ->and(mb_strlen($byType['quote'][0]['data']['text']))->toBe(1000)
        ->and(mb_strlen($byType['quote'][0]['data']['translation']))->toBe(1000);

    $before = $blocks;
    blkSave($blocks);

    expect(blkStored())->toEqual($before);
});

it('AC-1: turns a quote without arabic text into a left to right quote', function () {
    profilRawSet('portal.archived_footer_quote', ['arabic' => null, 'translation' => 'Hanya terjemah', 'source' => 'Sumber']);

    blkMigration()->up();

    $quote = collect(blkStored())->firstWhere('type', 'quote');

    expect($quote['data'])->toMatchArray(['text' => 'Hanya terjemah', 'rtl' => false, 'source' => 'Sumber'])
        ->and($quote['data']['translation'] ?? null)->toBeNull();
});

it('AC-1: is idempotent and never overwrites existing blocks or deletes the old keys then', function () {
    profilRawSet('portal.hero_title', 'Judul lama');
    $existing = [blk('cta', ['heading' => 'Sudah ada', 'variant' => 'light'])];
    blkSet($existing);

    blkMigration()->up();

    expect(blkStored())->toEqual($existing)
        ->and(profilRawGet('portal.hero_title'))->toBe('Judul lama');

    DB::table('settings')->where('key', 'portal.home_blocks')->delete();
    blkMigration()->up();
    $first = blkStored();
    blkMigration()->up();

    expect($first)->not->toBeEmpty()->and(blkStored())->toEqual($first)->and(profilRawHas('portal.hero_title'))->toBeFalse();
});

it('AC-2: writes nothing on a database that is completely empty', function () {
    foreach (['settings', 'pages', 'posts', 'albums', 'videos'] as $table) {
        DB::table($table)->delete();
    }

    blkMigration()->up();

    expect(profilRawHas('portal.home_blocks'))->toBeFalse();
});

it('AC-2: gives a seeded instance without home fields a hero and the four feeds like the home page it had', function () {
    expect(DB::table('settings')->count())->toBeGreaterThan(0);

    blkMigration()->up();

    $blocks = blkStored();

    expect(array_column($blocks, 'type'))->toBe(['hero', 'feed', 'feed', 'feed', 'feed'])
        ->and($blocks[0]['data']['title'] ?? null)->toBeNull()
        ->and($blocks[0]['data']['primary'])->toMatchArray(['type' => 'psb.register']);
});

it('AC-2: tolerates broken old values as empty', function () {
    profilRawSet('portal.highlights', 'RAW:{bukan json');
    profilRawSet('portal.archived_footer_quote', 'RAW:"teks"');
    profilRawSet('portal.hero_album_id', 'abc');
    profilRawSet('portal.profile_video_id', 'RAW:"x"');

    blkMigration()->up();

    expect(array_column(blkStored(), 'type'))->toBe(['hero', 'feed', 'feed', 'feed', 'feed']);
});

it('AC-2: down only drops the home blocks and leaves the old keys alone', function () {
    blkSet([blk('hero', ['title' => 'Judul']), blk('cta', ['heading' => 'Ajak', 'variant' => 'light'])]);
    profilRawSet('portal.hero_title', 'Judul lama');

    blkMigration()->down();

    expect(profilRawHas('portal.home_blocks'))->toBeFalse()
        ->and(profilRawGet('portal.hero_title'))->toBe('Judul lama')
        ->and(profilRawHas('portal.hero_greeting'))->toBeFalse()
        ->and(profilRawHas('portal.highlights'))->toBeFalse();
});
