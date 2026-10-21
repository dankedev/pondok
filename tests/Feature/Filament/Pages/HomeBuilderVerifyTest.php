<?php

use App\Actions\Konten\StorePublicImage;
use App\Filament\Pages\Site\Home;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| F045 VERIFY: celah di luar test RED. Tombol blok yang merujuk halaman/kategori terhapus tidak boleh
| membuat admin terjebak galat saat mengubah blok lain, dan gambar blok tidak boleh hilang saat simpan ulang.
*/

beforeEach(function () {
    kontenBoot();
});

it('VERIFY: lets the admin save other blocks when a button points to a page or category that was deleted', function (string $kind) {
    $gone = $kind === 'page' ? staticPage(['slug' => 'lama']) : kontenCategory('post', 'Lama');
    $button = $kind === 'page'
        ? ['type' => 'page', 'page_id' => $gone->id]
        : ['type' => 'category', 'category_id' => $gone->id];
    blkSet([blk('cta', ['heading' => 'Ajakan', 'variant' => 'light', 'primary' => $button]), blk('quote', ['text' => 'Teks lama'])]);
    $gone->forceDelete();

    $this->actingAs(kontenUser('admin-konten'));
    $component = Livewire::test(Home::class);
    $state = $component->get('data.home_blocks');
    $quoteKey = array_keys($state)[1];

    $component->set("data.home_blocks.{$quoteKey}.data.text", 'Teks baru')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(blkStored()[1]['data']['text'])->toBe('Teks baru');
})->with(['page', 'category']);

it('VERIFY: keeps the stored block image when another block is changed and saved', function () {
    Storage::fake('public');
    blkSave([blk('hero', ['title' => 'H', 'image' => kontenImage('a.jpg'), 'image_alt' => 'A']), blk('quote', ['text' => 'Lama'])]);
    $path = blkStored()[0]['data']['image'];

    $this->actingAs(kontenUser('admin-konten'));
    $component = Livewire::test(Home::class);
    $quoteKey = array_keys($component->get('data.home_blocks'))[1];

    $component->set("data.home_blocks.{$quoteKey}.data.text", 'Baru')->call('save')->assertHasNoFormErrors();

    expect(blkStored()[0]['data']['image'])->toBe($path)
        ->and(Storage::disk('public')->exists($path))->toBeTrue()
        ->and(blkStored()[1]['data']['text'])->toBe('Baru');
});

it('VERIFY: rejects a forged image path from the client on the field of the right block', function () {
    $this->actingAs(kontenUser('admin-konten'));

    Livewire::test(Home::class)
        ->set('data.home_blocks', [
            'blok-a' => ['type' => 'hero', 'data' => ['visible' => true, 'title' => 'H', 'image' => ['x' => 'portal/blocks/palsu.jpg'], 'image_alt' => 'A', 'primary' => ['type' => 'none'], 'secondary' => ['type' => 'none']]],
        ])
        ->call('save')
        ->assertHasErrors(['data.home_blocks.blok-a.data.image' => [__('pondok.konten.errors.image_invalid', ['max' => StorePublicImage::MAX_KILOBYTES])]]);

    expect(blkStored())->toBe([]);
});

it('hides the hero album picker while the konten module is off and keeps the stored album on save', function () {
    $album = kontenAlbum();
    blkSet([blk('hero', ['title' => 'H', 'album_id' => $album->id]), blk('quote', ['text' => 'Lama'])]);
    $this->actingAs(kontenUser('admin-konten'));

    Setting::set('modules.konten.enabled', false);
    $component = Livewire::test(Home::class);
    [$heroKey, $quoteKey] = array_keys($component->get('data.home_blocks'));

    $component->assertSchemaComponentHidden("home_blocks.{$heroKey}.data.album_id")
        ->set("data.home_blocks.{$quoteKey}.data.text", 'Baru')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(blkStored()[0]['data']['album_id'])->toBe($album->id);

    Setting::set('modules.konten.enabled', true);
    $component = Livewire::test(Home::class);
    $heroKey = array_keys($component->get('data.home_blocks'))[0];
    $component->assertSchemaComponentVisible("home_blocks.{$heroKey}.data.album_id");
});

it('offers the video and feed blocks only while the konten module is on and keeps stored ones on save', function () {
    $video = kontenVideo();
    blkSet([blk('video', ['heading' => 'V', 'video_id' => $video->id]), blk('feed', ['source' => 'posts', 'heading' => 'F'])]);
    $this->actingAs(kontenUser('admin-konten'));
    $pickerBlocks = fn ($component): array => array_map(
        fn ($block): string => $block->getName(),
        array_values($component->instance()->form->getComponent('home_blocks')->getBlockPickerBlocks()),
    );

    Setting::set('modules.konten.enabled', false);
    $component = Livewire::test(Home::class);
    [$videoKey, $feedKey] = array_keys($component->get('data.home_blocks'));

    expect($pickerBlocks($component))->toBe(['hero', 'text_image', 'features', 'quote', 'cta']);

    $component->assertSchemaComponentVisible("home_blocks.{$videoKey}.data.video_id")
        ->assertSchemaComponentVisible("home_blocks.{$feedKey}.data.source")
        ->set("data.home_blocks.{$videoKey}.data.heading", 'Video baru')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(blkStored())->sequence(
        fn ($block) => $block->type->toBe('video')->data->toMatchArray(['heading' => 'Video baru', 'video_id' => $video->id]),
        fn ($block) => $block->type->toBe('feed')->data->toMatchArray(['source' => 'posts', 'heading' => 'F']),
    );

    Setting::set('modules.konten.enabled', true);

    expect($pickerBlocks(Livewire::test(Home::class)))->toContain('video', 'feed');
});

it('VERIFY: reads the profile settings in one query and survives wrongly typed stored fields', function () {
    blkSet([
        blk('hero', ['title' => ['x'], 'eyebrow' => 5, 'primary' => ['type' => 'url', 'label' => 'A', 'url' => 7]]),
        blk('features', ['heading' => 'F', 'items' => 'bukan-daftar']),
        blk('features', ['heading' => 'G', 'items' => [['title' => ['a'], 'text' => null], 'rusak']]),
        blk('quote', ['text' => ['a'], 'rtl' => 'ya']),
    ]);
    DB::enableQueryLog();

    blkHome();

    $settingsQueries = collect(DB::getQueryLog())->filter(fn (array $q): bool => preg_match('/from ["`]settings["`] where ["`]key["`] in/i', $q['query']) === 1)->count();

    expect($settingsQueries)->toBe(1);
});
