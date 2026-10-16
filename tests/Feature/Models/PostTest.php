<?php

use App\Models\Post;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

beforeEach(function () {
    $this->f = kontenBoot();
});

it('AC-18: published scope excludes drafts, scheduled and trashed posts', function () {
    $a = kontenPost(['title' => 'A', 'status' => 'terbit', 'published_at' => '2026-10-14 08:00:00']);
    $b = kontenPost(['title' => 'B', 'status' => 'terbit', 'published_at' => '2026-10-20 08:00:00']);
    kontenPost(['title' => 'C', 'status' => 'draft', 'published_at' => '2026-10-01 08:00:00']);
    $d = kontenPost(['title' => 'D', 'status' => 'terbit', 'published_at' => '2026-10-01 08:00:00']);
    $d->delete();
    kontenPost(['title' => 'E', 'status' => 'terbit', 'published_at' => null]);

    expect(Post::published()->pluck('title')->all())->toBe(['A'])
        ->and(Post::published(CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Jakarta'))->pluck('title')->sort()->values()->all())->toBe(['A', 'B'])
        ->and($a->displayStatus())->toBe('terbit')
        ->and($b->displayStatus())->toBe('terjadwal');
});

it('AC-18: published scope includes a post published exactly now', function () {
    kontenPost(['title' => 'Tepat', 'status' => 'terbit', 'published_at' => '2026-10-15 10:00:00']);

    expect(Post::published()->count())->toBe(1);
});

it('AC-18: display status and its color', function () {
    $published = kontenPost(['status' => 'terbit', 'published_at' => '2026-10-14 08:00:00']);
    $scheduled = kontenPost(['status' => 'terbit', 'published_at' => '2026-10-20 08:00:00']);
    $draft = kontenPost(['status' => 'draft', 'published_at' => '2026-10-01 08:00:00']);

    expect($draft->displayStatus())->toBe('draft')
        ->and([$draft->displayStatusColor(), $published->displayStatusColor(), $scheduled->displayStatusColor()])->toBe(['gray', 'success', 'info'])
        ->and(Post::DISPLAY_SCHEDULED)->toBe('terjadwal')
        ->and(Post::STATUSES)->toBe(['draft', 'terbit'])
        ->and(Post::TYPES)->toEqualCanonicalizing(['kegiatan', 'prestasi', 'berita']);
});

it('AC-18: filters posts by type', function () {
    kontenPost(['title' => 'Berita', 'type' => 'berita']);
    kontenPost(['title' => 'Kegiatan', 'type' => 'kegiatan']);

    expect(Post::ofType('kegiatan')->pluck('title')->all())->toBe(['Kegiatan']);
});

it('AC-18: casts meta to an array and published_at to a datetime', function () {
    $post = kontenPost(['meta' => ['a' => 1], 'status' => 'terbit', 'published_at' => '2026-10-14 08:00:00']);

    expect($post->fresh()->meta)->toBe(['a' => 1])
        ->and($post->fresh()->published_at)->toBeInstanceOf(CarbonInterface::class);
});

it('AC-2: reads a post without an author as having no author', function () {
    $post = kontenPost(['author_id' => null]);

    expect($post->fresh()->author)->toBeNull();
});
