<?php

use App\Filament\Pages\Site\Home;
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
        ->call('save');

    expect(blkStored())->toBe([]);
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
