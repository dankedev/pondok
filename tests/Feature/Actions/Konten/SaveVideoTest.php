<?php

use App\Actions\Konten\DeleteVideo;
use App\Actions\Konten\SaveVideo;
use App\Models\Video;

beforeEach(function () {
    $this->f = kontenBoot();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function kontenSaveVideo(array $overrides = [], ?Video $video = null): Video
{
    return app(SaveVideo::class)->handle(['title' => 'Profil Pondok', 'youtube' => 'https://youtu.be/dQw4w9WgXcQ', ...$overrides], $video);
}

it('AC-29: extracts the youtube id from a url or a raw id', function (string $input) {
    $video = kontenSaveVideo(['youtube' => $input])->fresh();

    expect($video->youtube_id)->toBe('dQw4w9WgXcQ')
        ->and($video->embedUrl())->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
})->with(['url' => 'https://youtu.be/dQw4w9WgXcQ', 'raw id' => 'dQw4w9WgXcQ']);

it('AC-29: applies defaults for optional fields', function () {
    $video = kontenSaveVideo()->fresh();

    expect($video)->title->toBe('Profil Pondok')->sort_order->toBe(0)->is_featured->toBeFalse()->description->toBeNull();
});

it('AC-29: stores description as plain text, featured flag and sort order', function () {
    $video = kontenSaveVideo(['description' => '<b>Dokumenter</b>', 'is_featured' => true, 'sort_order' => 5])->fresh();

    expect($video)->description->toBe('Dokumenter')->is_featured->toBeTrue()->sort_order->toBe(5);
});

it('AC-29: rejects invalid input without writing a row', function (array $overrides, string $reason, string $field) {
    kontenAssertRejected(fn () => kontenSaveVideo($overrides), $reason, $field);

    expect(Video::query()->count())->toBe(0);
})->with([
    'blank youtube' => [['youtube' => ''], 'youtube_required', 'youtube'],
    'vimeo' => [['youtube' => 'https://vimeo.com/1'], 'youtube_invalid', 'youtube'],
    'blank title' => [['title' => ''], 'title_required', 'title'],
    'title too long' => [['title' => str_repeat('a', 256)], 'too_long', 'title'],
    'description too long' => [['description' => str_repeat('a', 2001)], 'too_long', 'description'],
    'sort order text' => [['sort_order' => 'abc'], 'sort_order_invalid', 'sort_order'],
    'sort order negative' => [['sort_order' => -1], 'sort_order_invalid', 'sort_order'],
    'sort order too big' => [['sort_order' => 40000], 'sort_order_invalid', 'sort_order'],
]);

it('AC-29: allows the same youtube video twice and updates in place', function () {
    $first = kontenSaveVideo();
    $second = kontenSaveVideo(['title' => 'Salinan']);

    expect(Video::query()->count())->toBe(2)->and($first->id)->not->toBe($second->id);

    $updated = kontenSaveVideo(['title' => 'Judul baru', 'youtube' => 'aaaaaaaaaaa'], $first);

    expect($updated->id)->toBe($first->id)->and($first->fresh())->title->toBe('Judul baru')->youtube_id->toBe('aaaaaaaaaaa');
});

it('AC-29: deletes a video permanently', function () {
    $video = kontenVideo();

    app(DeleteVideo::class)->handle($video);

    expect(Video::query()->count())->toBe(0);
});
