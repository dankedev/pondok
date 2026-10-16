<?php

use App\Actions\Konten\AddAlbumPhotos;
use App\Actions\Konten\DeleteAlbum;
use App\Actions\Konten\DeletePhoto;
use App\Actions\Konten\SaveAlbum;
use App\Actions\Konten\UpdatePhoto;
use App\Models\Album;
use App\Models\Category;
use App\Models\Photo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->f = kontenBoot();
    $this->category = kontenCategory('album', 'Fasilitas');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function kontenSaveAlbum(array $overrides = [], ?Album $album = null): Album
{
    return app(SaveAlbum::class)->handle(['title' => 'Fasilitas Asrama', 'category_id' => test()->category->id, ...$overrides], $album);
}

it('AC-24: creates an album with a generated slug', function () {
    $album = kontenSaveAlbum(['description' => '<b>Foto</b> asrama'])->fresh();

    expect($album)->title->toBe('Fasilitas Asrama')->slug->toBe('fasilitas-asrama')->category_id->toBe($this->category->id)->description->toBe('Foto asrama');
});

it('AC-24: suffixes the slug of a second album with the same title', function () {
    kontenSaveAlbum();

    expect(kontenSaveAlbum()->slug)->toBe('fasilitas-asrama-2');
});

it('AC-24: requires an album category', function (?string $category) {
    $id = match ($category) {
        'post' => kontenCategory('post', 'Berita')->id,
        'unknown' => 9999,
        default => null,
    };

    kontenAssertRejected(fn () => kontenSaveAlbum(['category_id' => $id]), 'invalid_category', 'category_id');

    expect(Album::query()->count())->toBe(0);
})->with(['missing' => [null], 'post category' => ['post'], 'unknown id' => ['unknown']]);

it('AC-24: validates title, slug and description', function (array $overrides, string $reason, string $field) {
    kontenAssertRejected(fn () => kontenSaveAlbum($overrides), $reason, $field);

    expect(Album::query()->count())->toBe(0);
})->with([
    'blank title' => [['title' => ' '], 'title_required', 'title'],
    'title too long' => [['title' => str_repeat('a', 256)], 'too_long', 'title'],
    'description too long' => [['description' => str_repeat('a', 2001)], 'too_long', 'description'],
    'slug too long' => [['slug' => str_repeat('a', 201)], 'too_long', 'slug'],
    'slug invalid' => [['slug' => '!!!'], 'slug_invalid', 'slug'],
]);

it('AC-24: rejects an explicit slug that another album uses and lets an album keep its own', function () {
    $album = kontenSaveAlbum(['slug' => 'sama']);

    kontenAssertRejected(fn () => kontenSaveAlbum(['title' => 'Lain', 'slug' => 'sama']), 'slug_taken', 'slug');

    expect(kontenSaveAlbum(['slug' => 'sama', 'title' => 'Judul baru'], $album)->fresh()->title)->toBe('Judul baru');
});

it('AC-25: adds photos after the highest sort order and stores them under the album folder', function () {
    $album = kontenAlbum(['category_id' => $this->category->id]);
    kontenPhoto($album, ['sort_order' => 1]);

    $photos = app(AddAlbumPhotos::class)->handle($album, [kontenImage('1.jpg'), kontenImage('2.jpg'), kontenImage('3.jpg')]);

    expect($photos)->toHaveCount(3)
        ->and($photos->pluck('sort_order')->all())->toBe([2, 3, 4])
        ->and(Photo::query()->where('album_id', $album->id)->count())->toBe(4);

    foreach ($photos as $photo) {
        expect($photo->path)->toStartWith("albums/{$album->id}/")->and(Storage::disk('public')->exists($photo->path))->toBeTrue()
            ->and($photo->url())->toBe(Storage::disk('public')->url($photo->path));
    }
});

it('AC-25: starts sort order at 1 on an empty album and applies the caption to every photo', function () {
    $album = kontenAlbum(['category_id' => $this->category->id]);

    $photos = app(AddAlbumPhotos::class)->handle($album, [kontenImage('1.jpg'), kontenImage('2.jpg')], 'Halaman asrama');

    expect($photos->pluck('sort_order')->all())->toBe([1, 2])->and($photos->pluck('caption')->unique()->all())->toBe(['Halaman asrama']);
});

it('AC-25: rejects the whole upload when one file is invalid', function () {
    $album = kontenAlbum(['category_id' => $this->category->id]);

    kontenAssertRejected(fn () => app(AddAlbumPhotos::class)->handle($album, [kontenImage('1.jpg'), kontenPdf(), kontenImage('3.jpg')]), 'image_invalid', 'photos');

    expect(Photo::query()->count())->toBe(0)->and(kontenFiles())->toBe([]);
});

it('AC-25: rejects an oversized photo and a caption over 255 characters', function () {
    $album = kontenAlbum(['category_id' => $this->category->id]);

    kontenAssertRejected(fn () => app(AddAlbumPhotos::class)->handle($album, [kontenImage('1.jpg', 2049)]), 'image_invalid', 'photos');
    kontenAssertRejected(fn () => app(AddAlbumPhotos::class)->handle($album, [kontenImage('1.jpg')], str_repeat('a', 256)), 'too_long', 'caption');

    expect(Photo::query()->count())->toBe(0)->and(kontenFiles())->toBe([]);
});

it('AC-25: rejects a webp photo and stores nothing', function () {
    $album = kontenAlbum(['category_id' => $this->category->id]);

    kontenAssertRejected(fn () => app(AddAlbumPhotos::class)->handle($album, [kontenImage('1.jpg'), UploadedFile::fake()->create('2.webp', 10, 'image/webp')]), 'image_invalid', 'photos');

    expect(Photo::query()->count())->toBe(0)->and(kontenFiles())->toBe([]);
});

it('AC-25: requires at least one file and at most 20', function () {
    $album = kontenAlbum(['category_id' => $this->category->id]);

    kontenAssertRejected(fn () => app(AddAlbumPhotos::class)->handle($album, []), 'photos_required', 'photos');
    kontenAssertRejected(fn () => app(AddAlbumPhotos::class)->handle($album, array_map(fn (int $i): UploadedFile => kontenImage("{$i}.jpg", 10), range(1, 21))), 'photos_too_many', 'photos');

    expect(Photo::query()->count())->toBe(0)->and(kontenFiles())->toBe([])
        ->and(AddAlbumPhotos::MAX_FILES_PER_UPLOAD)->toBe(20);
});

it('AC-25: accepts exactly 20 files', function () {
    $album = kontenAlbum(['category_id' => $this->category->id]);

    $photos = app(AddAlbumPhotos::class)->handle($album, array_map(fn (int $i): UploadedFile => kontenImage("{$i}.jpg", 10), range(1, 20)));

    expect($photos)->toHaveCount(20)->and(Photo::query()->count())->toBe(20);
});

it('AC-26: validates caption and sort order when updating a photo', function (array $data, string $reason, string $field) {
    $photo = kontenPhoto(kontenAlbum(['category_id' => $this->category->id]), ['caption' => 'Lama', 'sort_order' => 3]);

    kontenAssertRejected(fn () => app(UpdatePhoto::class)->handle($photo, $data), $reason, $field);

    expect($photo->fresh())->caption->toBe('Lama')->sort_order->toBe(3);
})->with([
    'caption too long' => [['caption' => str_repeat('a', 256), 'sort_order' => 1], 'too_long', 'caption'],
    'negative order' => [['caption' => 'x', 'sort_order' => -1], 'sort_order_invalid', 'sort_order'],
    'order too big' => [['caption' => 'x', 'sort_order' => 40000], 'sort_order_invalid', 'sort_order'],
    'order text' => [['caption' => 'x', 'sort_order' => 'abc'], 'sort_order_invalid', 'sort_order'],
]);

it('AC-26: saves a valid caption and sort order and clears a blank caption', function () {
    $photo = kontenPhoto(kontenAlbum(['category_id' => $this->category->id]), ['caption' => 'Lama']);

    app(UpdatePhoto::class)->handle($photo, ['caption' => '  Baru  ', 'sort_order' => 7]);

    expect($photo->fresh())->caption->toBe('Baru')->sort_order->toBe(7);

    app(UpdatePhoto::class)->handle($photo, ['caption' => '   ', 'sort_order' => 32767]);

    expect($photo->fresh())->caption->toBeNull()->sort_order->toBe(32767);
});

it('AC-27: deletes a photo row and its file', function () {
    $photo = kontenPhoto(kontenAlbum(['category_id' => $this->category->id]));
    $keep = kontenPhoto($photo->album);

    app(DeletePhoto::class)->handle($photo);

    expect(Photo::query()->find($photo->id))->toBeNull()
        ->and(Storage::disk('public')->exists($photo->path))->toBeFalse()
        ->and(Storage::disk('public')->exists($keep->path))->toBeTrue();
});

it('AC-27: deletes an album with its photos and files but not the category', function () {
    $album = kontenAlbum(['category_id' => $this->category->id]);
    $one = kontenPhoto($album);
    $two = kontenPhoto($album);
    $other = kontenPhoto(kontenAlbum(['category_id' => $this->category->id]));

    app(DeleteAlbum::class)->handle($album);

    expect(Album::query()->find($album->id))->toBeNull()
        ->and(Photo::query()->where('album_id', $album->id)->count())->toBe(0)
        ->and(Storage::disk('public')->exists($one->path))->toBeFalse()
        ->and(Storage::disk('public')->exists($two->path))->toBeFalse()
        ->and(Storage::disk('public')->exists($other->path))->toBeTrue()
        ->and(Category::query()->find($this->category->id))->not->toBeNull();
});

it('AC-24: orders photos by sort order then id and picks the first as cover', function () {
    $album = kontenAlbum(['category_id' => $this->category->id]);
    $second = kontenPhoto($album, ['sort_order' => 2]);
    $first = kontenPhoto($album, ['sort_order' => 1]);
    $tie = kontenPhoto($album, ['sort_order' => 1]);

    expect($album->photos()->pluck('id')->all())->toBe([$first->id, $tie->id, $second->id])
        ->and($album->coverPhoto->id)->toBe($first->id);

    $listed = Album::query()->withCount('photos')->with('coverPhoto')->find($album->id);

    expect($listed->photos_count)->toBe(3)->and($listed->coverPhoto->id)->toBe($first->id)
        ->and(kontenAlbum(['category_id' => $this->category->id])->coverPhoto)->toBeNull();
});
