<?php

use App\Filament\Resources\Albums\Pages\CreateAlbum;
use App\Filament\Resources\Albums\Pages\EditAlbum;
use App\Filament\Resources\Albums\Pages\ListAlbums;
use App\Filament\Resources\Albums\RelationManagers\PhotosRelationManager;
use App\Models\Album;
use App\Models\Photo;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->f = kontenBoot();
    $this->category = kontenCategory('album', 'Fasilitas');
    $this->album = kontenAlbum(['title' => 'Fasilitas Asrama', 'category_id' => $this->category->id]);
    $this->actingAs(kontenUser('admin-konten'));
});

function kontenPhotos(Album $album): Testable
{
    return Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $album, 'pageClass' => EditAlbum::class]);
}

it('AC-28: uploads photos from the album page', function () {
    kontenPhotos($this->album)
        ->assertSuccessful()
        ->assertActionVisible(TestAction::make('upload')->table())
        ->callAction(TestAction::make('upload')->table(), ['photos' => [kontenImage('1.jpg'), kontenImage('2.jpg')], 'caption' => 'Ruang belajar'])
        ->assertHasNoFormErrors();

    $photos = Photo::query()->where('album_id', $this->album->id)->orderBy('sort_order')->get();

    expect($photos)->toHaveCount(2)->and($photos->pluck('sort_order')->all())->toBe([1, 2])->and($photos->pluck('caption')->unique()->all())->toBe(['Ruang belajar']);

    foreach ($photos as $photo) {
        expect(Storage::disk('public')->exists($photo->path))->toBeTrue();
    }
});

it('AC-28: stores nothing when one of the uploaded files is not an image', function () {
    kontenPhotos($this->album)
        ->callAction(TestAction::make('upload')->table(), ['photos' => [kontenImage('1.jpg'), kontenPdf()]]);

    expect(Photo::query()->count())->toBe(0)->and(kontenFiles())->toBe([]);
});

it('AC-28: deletes a photo with its file', function () {
    $photo = kontenPhoto($this->album);

    kontenPhotos($this->album)
        ->assertCanSeeTableRecords([$photo])
        ->assertActionVisible(TestAction::make('delete')->table($photo))
        ->callAction(TestAction::make('delete')->table($photo));

    expect(Photo::query()->find($photo->id))->toBeNull()->and(Storage::disk('public')->exists($photo->path))->toBeFalse();
});

it('AC-28: edits caption and sort order of a photo', function () {
    $photo = kontenPhoto($this->album, ['caption' => 'Lama', 'sort_order' => 1]);

    kontenPhotos($this->album)
        ->callAction(TestAction::make('edit')->table($photo), ['caption' => 'Baru', 'sort_order' => 4])
        ->assertHasNoFormErrors();

    expect($photo->fresh())->caption->toBe('Baru')->sort_order->toBe(4);
});

it('F065: replaces the image of a photo and removes the old file', function () {
    $photo = kontenPhoto($this->album, ['caption' => 'Lama']);
    $oldPath = $photo->path;

    kontenPhotos($this->album)
        ->callAction(TestAction::make('edit')->table($photo), ['image' => kontenImage('baru.jpg'), 'caption' => 'Lama', 'sort_order' => 1])
        ->assertHasNoFormErrors();

    $photo->refresh();

    expect($photo->path)->not->toBe($oldPath)
        ->and(Storage::disk('public')->exists($photo->path))->toBeTrue()
        ->and(Storage::disk('public')->exists($oldPath))->toBeFalse();
});

it('F065: keeps the image when only the caption changes and rejects a non-image', function () {
    $photo = kontenPhoto($this->album);
    $path = $photo->path;

    kontenPhotos($this->album)
        ->callAction(TestAction::make('edit')->table($photo), ['image' => kontenPdf(), 'caption' => 'Baru', 'sort_order' => 1])
        ->assertHasFormErrors(['image']);

    kontenPhotos($this->album)
        ->callAction(TestAction::make('edit')->table($photo), ['caption' => 'Baru', 'sort_order' => 1])
        ->assertHasNoFormErrors();

    expect($photo->fresh()->path)->toBe($path)->and(Storage::disk('public')->exists($path))->toBeTrue();
});

it('F065: deletes several photos at once with their files', function () {
    $photos = collect([kontenPhoto($this->album), kontenPhoto($this->album)]);
    $kept = kontenPhoto($this->album);

    kontenPhotos($this->album)
        ->selectTableRecords($photos->pluck('id')->all())
        ->callAction(TestAction::make('delete')->table()->bulk());

    expect(Photo::query()->pluck('id')->all())->toBe([$kept->id]);

    foreach ($photos as $photo) {
        expect(Storage::disk('public')->exists($photo->path))->toBeFalse();
    }
});

it('AC-28: lists only the photos of this album in sort order', function () {
    $second = kontenPhoto($this->album, ['sort_order' => 2]);
    $first = kontenPhoto($this->album, ['sort_order' => 1]);
    $other = kontenPhoto(kontenAlbum(['category_id' => $this->category->id]));

    kontenPhotos($this->album)
        ->assertCanSeeTableRecords([$first, $second], inOrder: true)
        ->assertCanNotSeeTableRecords([$other]);
});

it('AC-28: forbids album pages to bendahara', function () {
    $this->actingAs(kontenUser('bendahara'));

    $this->get("/admin/albums/{$this->album->id}/edit")->assertForbidden();
    $this->get('/admin/albums')->assertForbidden();
});

it('AC-28: lets admin-konten open the album edit page', function () {
    $this->get("/admin/albums/{$this->album->id}/edit")->assertOk();
});

it('AC-24: creates an album from the form', function () {
    Livewire::test(CreateAlbum::class)
        ->fillForm(['title' => 'Kegiatan Ramadan', 'category_id' => $this->category->id, 'description' => 'Foto Ramadan'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Album::query()->where('slug', 'kegiatan-ramadan')->sole())->description->toBe('Foto Ramadan');
});

it('AC-24: offers only album categories and requires one', function () {
    $post = kontenCategory('post', 'Berita');

    Livewire::test(CreateAlbum::class)
        ->assertSchemaComponentExists('category_id', checkComponentUsing: fn ($select): bool => array_keys($select->getOptions()) === [$this->category->id])
        ->fillForm(['title' => 'Tanpa kategori', 'category_id' => null])
        ->call('create')
        ->assertHasFormErrors(['category_id']);

    expect(Album::query()->count())->toBe(1)->and($post->type)->toBe('post');
});

it('AC-27: deletes an album with its photos from the list', function () {
    $photo = kontenPhoto($this->album);

    Livewire::test(ListAlbums::class)
        ->assertCanSeeTableRecords([$this->album])
        ->callAction(TestAction::make('delete')->table($this->album));

    expect(Album::query()->find($this->album->id))->toBeNull()
        ->and(Photo::query()->count())->toBe(0)
        ->and(Storage::disk('public')->exists($photo->path))->toBeFalse();
});

it('AC-27: shows the photo count and filters albums by category', function () {
    kontenPhoto($this->album);
    kontenPhoto($this->album);
    $other = kontenCategory('album', 'Kegiatan');
    $elsewhere = kontenAlbum(['title' => 'Album lain', 'category_id' => $other->id]);

    Livewire::test(ListAlbums::class)
        ->assertTableColumnStateSet('photos_count', 2, record: $this->album)
        ->filterTable('category_id', $other->id)
        ->assertCanSeeTableRecords([$elsewhere])
        ->assertCanNotSeeTableRecords([$this->album]);
});
