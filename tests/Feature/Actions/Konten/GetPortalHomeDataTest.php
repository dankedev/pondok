<?php

use App\Actions\Konten\GetPortalHomeData;
use App\Models\Setting;

/*
| F045 AC-7 dan AC-12 (lapisan data). Kontrak: `GetPortalHomeData::handle()` mengembalikan
| `{school, blocks, hasHero}`. `blocks` adalah daftar blok siap render; tiap elemen memiliki `type`
| (dibaca lewat `data_get($block, 'type')`, array atau objek). Blok tersembunyi, rusak, bertipe
| tak dikenal, atau tanpa isi dibuang; urutan tersimpan dipertahankan.
*/

beforeEach(function () {
    portalBoot();
});

function blkHomeData(): array
{
    app()->forgetScopedInstances();

    return app(GetPortalHomeData::class)->handle();
}

it('AC-7: returns only renderable blocks in saved order and reports whether a hero is shown', function () {
    $video = kontenVideo();
    $gone = kontenVideo();
    $gone->delete();
    blkSet([
        blk('cta', ['heading' => 'Satu', 'variant' => 'light']),
        blk('hero', ['title' => 'Hero tersembunyi'], visible: false),
        blk('text_image', ['heading' => '', 'text' => '']),
        blk('video', ['video_id' => $gone->id]),
        blk('video', ['video_id' => $video->id]),
        blk('feed', ['source' => 'posts']),
        ['type' => 'tidak-ada', 'visible' => true, 'data' => []],
        blk('quote', ['text' => 'Teks']),
    ]);

    $data = blkHomeData();

    expect(array_keys($data))->toContain('school', 'blocks', 'hasHero')
        ->and(array_map(fn (mixed $block): string => data_get($block, 'type'), $data['blocks']))->toBe(['cta', 'video', 'quote'])
        ->and($data['hasHero'])->toBeFalse();

    blkSet([blk('hero', ['title' => 'Hero tampil']), blk('cta', ['heading' => 'Satu', 'variant' => 'light'])]);

    expect(blkHomeData()['hasHero'])->toBeTrue();
});

it('AC-7: returns no blocks and no hero for an install without blocks, even when content tables hold data', function () {
    portalSeedContent();

    $data = blkHomeData();

    expect($data['blocks'])->toBe([])->and($data['hasHero'])->toBeFalse()->and($data['school']['name'])->toBe("Ma'had Al-Khair");
});

it('AC-7: leaves out content blocks when the content module is off but keeps the others', function () {
    $video = kontenVideo();
    Setting::set('modules.konten.enabled', false);
    blkSet([blk('video', ['video_id' => $video->id]), blk('feed', ['source' => 'posts']), blk('quote', ['text' => 'Teks'])]);

    expect(array_map(fn (mixed $block): string => data_get($block, 'type'), blkHomeData()['blocks']))->toBe(['quote']);
});
