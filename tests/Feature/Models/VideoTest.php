<?php

use App\Models\Video;

beforeEach(function () {
    $this->f = kontenBoot();
});

it('AC-30: orders featured videos first, then by sort order, then newest', function () {
    $v1 = kontenVideo(['title' => 'V1', 'is_featured' => true, 'sort_order' => 2]);
    $v2 = kontenVideo(['title' => 'V2', 'is_featured' => true, 'sort_order' => 1]);
    $v3 = kontenVideo(['title' => 'V3', 'is_featured' => false, 'sort_order' => 0]);

    expect(Video::ordered()->pluck('title')->all())->toBe(['V2', 'V1', 'V3'])
        ->and([$v1->id, $v2->id, $v3->id])->each->toBeInt();
});

it('AC-30: breaks ties by newest id first', function () {
    kontenVideo(['title' => 'Lama', 'sort_order' => 1]);
    kontenVideo(['title' => 'Baru', 'sort_order' => 1]);

    expect(Video::ordered()->pluck('title')->all())->toBe(['Baru', 'Lama']);
});

it('AC-29: builds the embed and watch urls', function () {
    $video = kontenVideo(['youtube_id' => 'dQw4w9WgXcQ']);

    expect($video->embedUrl())->toBe('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ')
        ->and($video->watchUrl())->toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
});
