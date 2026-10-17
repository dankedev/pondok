<?php

use App\Filament\Pages\Site\Home;
use App\Models\Setting;
use Illuminate\Support\Arr;
use Livewire\Livewire;

/*
| F045 AC-13. Kontrak halaman `App\Filament\Pages\Site\Home` (slug `site/home`, tombol simpan memanggil `save`).
| State form: `data.home_blocks` = Builder; tiap item `{type, data}` berkunci UUID, dengan `data.visible` (bool,
| Toggle "Tampilkan") di dalam `data` blok. Field blok sama dengan kunci data blok di rencana (`title`, `items.*.title`,
| `video_id`, `album_id`, `limit`, ...). Item tombol (`primary`, `secondary`, `button`) berstate `{type, label, page_id,
| category_id, url}` dengan `type = 'none'` untuk "Tanpa tombol". Saat disimpan, `visible` dipindah ke tingkat blok
| lalu `UpdatePortalHome` dipanggil. Galat Action `home_blocks.{i}.data.x` dipetakan ke `data.home_blocks.{uuid}.data.x`.
*/

beforeEach(function () {
    $this->f = kontenBoot();
});

/**
 * @return list<array<string, mixed>>
 */
function blkBuilderItems(mixed $state): array
{
    return array_values($state ?? []);
}

it('AC-13: lets the profile roles open the home builder and forbids the rest', function () {
    foreach (['super-admin' => 200, 'admin-sekolah' => 200, 'admin-konten' => 200, 'bendahara' => 403, 'admin-psb' => 403, 'kesantrian' => 403, 'musyrif' => 403, 'petugas-pos' => 403, 'wali' => 403] as $role => $status) {
        test()->actingAs(kontenUser($role))->get('/admin/site/home')->assertStatus($status);
    }
});

it('AC-13: fills the builder from the settings and empties references that no longer exist', function () {
    $video = kontenVideo();
    $goneVideo = kontenVideo();
    $goneAlbum = kontenAlbum();
    $goneVideo->delete();
    $goneAlbum->delete();
    blkSet([
        blk('hero', ['title' => 'Judul hero uji', 'album_id' => $goneAlbum->id]),
        blk('cta', ['heading' => 'Ajakan uji', 'variant' => 'navy'], visible: false),
        blk('video', ['video_id' => $video->id]),
        blk('video', ['video_id' => $goneVideo->id]),
    ]);
    $this->actingAs(kontenUser('admin-konten'));

    $items = blkBuilderItems(Livewire::test(Home::class)->get('data.home_blocks'));

    expect(array_column($items, 'type'))->toBe(['hero', 'cta', 'video', 'video'])
        ->and(array_column(array_column($items, 'data'), 'visible'))->toBe([true, false, true, true])
        ->and($items[0]['data'])->toMatchArray(['title' => 'Judul hero uji'])->and($items[0]['data']['album_id'] ?? null)->toBeNull()
        ->and($items[1]['data'])->toMatchArray(['heading' => 'Ajakan uji', 'variant' => 'navy'])
        ->and($items[2]['data']['video_id'])->toBe($video->id)
        ->and($items[3]['data']['video_id'] ?? null)->toBeNull();
});

it('AC-13: saves added, reordered, hidden and removed blocks through the action', function () {
    $this->actingAs(kontenUser('admin-konten'));
    $component = Livewire::test(Home::class)
        ->set('data.home_blocks', [
            'blok-a' => ['type' => 'cta', 'data' => ['visible' => true, 'heading' => 'Ajakan satu', 'variant' => 'navy', 'primary' => ['type' => 'posts', 'label' => 'Baca'], 'secondary' => ['type' => 'none']]],
            'blok-b' => ['type' => 'quote', 'data' => ['visible' => false, 'text' => 'Teks kutipan', 'rtl' => false]],
            'blok-c' => ['type' => 'feed', 'data' => ['visible' => true, 'source' => 'posts']],
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $stored = blkStored();

    expect(array_column($stored, 'type'))->toBe(['cta', 'quote', 'feed'])
        ->and(array_column($stored, 'visible'))->toBe([true, false, true])
        ->and($stored[0]['data'])->toMatchArray(['heading' => 'Ajakan satu', 'variant' => 'navy'])
        ->and($stored[0]['data']['primary'])->toMatchArray(['type' => 'posts', 'label' => 'Baca'])
        ->and($stored[0]['data']['secondary'] ?? null)->toBeNull()
        ->and($stored[2]['data'])->toMatchArray(['source' => 'posts'])
        ->and(kontenActivities()->where('description', 'portal_home_updated'))->toHaveCount(1);

    $state = $component->get('data.home_blocks');
    $keys = array_keys($state);

    $component->set('data.home_blocks', [$keys[2] => $state[$keys[2]], $keys[0] => $state[$keys[0]]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(array_column(blkStored(), 'type'))->toBe(['feed', 'cta']);

    $component->set('data.home_blocks', [])->call('save')->assertHasNoFormErrors();

    expect(blkStored())->toBe([]);
});

it('AC-13: shows an action error on the field of the right block and saves nothing', function () {
    $this->actingAs(kontenUser('admin-konten'));
    blkSave([blk('cta', ['heading' => 'Lama', 'variant' => 'light'])]);
    $before = profilSettingsSnapshot();

    Livewire::test(Home::class)
        ->set('data.home_blocks', [
            'blok-a' => ['type' => 'cta', 'data' => ['visible' => true, 'heading' => 'Sah', 'variant' => 'light', 'primary' => ['type' => 'none'], 'secondary' => ['type' => 'none']]],
            'blok-b' => ['type' => 'cta', 'data' => ['visible' => true, 'heading' => 'Bahaya', 'variant' => 'light', 'primary' => ['type' => 'url', 'label' => 'Klik', 'url' => 'javascript:alert(1)'], 'secondary' => ['type' => 'none']]],
        ])
        ->call('save')
        ->assertHasFormErrors(['home_blocks.blok-b.data.primary.url']);

    expect(profilSettingsSnapshot())->toBe($before);
});

it('AC-13: shows the helper texts without a greeting or an example that is built in', function () {
    blkSet([blk('hero', ['title' => 'Judul hero uji']), blk('quote', ['text' => 'q'])]);
    $blocks = __('pondok.school_profile.blocks');

    expect($blocks)->toBeArray()->not->toBeEmpty();

    foreach (Arr::dot($blocks) as $key => $text) {
        expect(preg_match('/bismillah|ahlan|assalamu/i', (string) $text))->toBe(0, "school_profile.blocks.{$key}");
    }

    $html = $this->actingAs(kontenUser('admin-konten'))->get('/admin/site/home')->assertOk()->getContent();

    expect($html)->not->toMatch('/\bpondok\.[a-z_]+\.[a-z_]+/')->not->toMatch('/bismillah|assalamu|ahlan/i')
        ->toContain('Pendidikan berasrama untuk putra jenjang SMP dan SMA');
});

it('AC-13: keeps the old fixed fields out of the form', function () {
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(Home::class)
        ->assertFormFieldExists('home_blocks')
        ->assertFormFieldDoesNotExist('hero_title')
        ->assertFormFieldDoesNotExist('highlights')
        ->assertFormFieldDoesNotExist('hero_album_id')
        ->assertFormFieldDoesNotExist('profile_video_id');

    expect(Setting::get('portal.hero_title'))->toBeNull();
});
