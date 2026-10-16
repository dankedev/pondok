<?php

use App\Actions\Konten\DeletePost;
use App\Actions\Konten\RestorePost;
use App\Actions\Konten\SavePost;
use App\Models\Post;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->f = kontenBoot();
    $this->editor = kontenUser('admin-konten');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function kontenSave(array $overrides = [], ?User $actor = null, ?Post $post = null): Post
{
    return app(SavePost::class)->handle(kontenPostData($overrides), $actor ?? test()->editor, $post);
}

it('AC-9: creates a draft post with a generated slug and the actor as author', function () {
    $post = kontenSave();

    expect(Post::query()->count())->toBe(1)
        ->and($post->fresh())
        ->type->toBe('berita')
        ->title->toBe('Wisuda Tahfidz 2026')
        ->slug->toBe('wisuda-tahfidz-2026')
        ->body->toBe('<p>Alhamdulillah</p>')
        ->author_id->toBe($this->editor->id)
        ->status->toBe('draft')
        ->published_at->toBeNull()
        ->unit_id->toBeNull()
        ->category_id->toBeNull()
        ->cover_path->toBeNull()
        ->youtube_url->toBeNull()
        ->excerpt->toBeNull();
});

it('AC-9: stores unit, category, plain-text excerpt and trimmed title', function () {
    $category = kontenCategory('post', 'Pengumuman');

    $post = kontenSave([
        'title' => '  Pengumuman Libur  ',
        'unit_id' => $this->f->sltp->id,
        'category_id' => $category->id,
        'excerpt' => '  <b>Ringkas</b> saja  ',
        'type' => 'kegiatan',
    ]);

    expect($post->fresh())
        ->title->toBe('Pengumuman Libur')
        ->unit_id->toBe($this->f->sltp->id)
        ->category_id->toBe($category->id)
        ->excerpt->toBe('Ringkas saja')
        ->type->toBe('kegiatan');
});

it('AC-10: appends a numeric suffix when the slug is taken, including soft-deleted posts', function () {
    $first = kontenPost(['title' => 'Wisuda Tahfidz 2026', 'slug' => 'wisuda-tahfidz-2026']);
    $first->delete();

    $second = kontenSave();
    $third = kontenSave();

    expect($second->slug)->toBe('wisuda-tahfidz-2026-2')
        ->and($third->slug)->toBe('wisuda-tahfidz-2026-3');
});

it('AC-10: transliterates Arabic titles into the slug', function () {
    expect(kontenSave(['title' => 'الحمد لله'])->slug)->toBe('alhmd-llh');
});

it('AC-10: falls back to "post" for titles without letters or digits', function () {
    expect(kontenSave(['title' => '★★★'])->slug)->toBe('post')
        ->and(kontenSave(['title' => '★★★'])->slug)->toBe('post-2');
});

it('AC-10: rejects an explicit slug longer than 200 characters', function () {
    $count = Post::query()->count();

    kontenAssertRejected(fn () => kontenSave(['slug' => str_repeat('a', 201)]), 'too_long', 'slug');

    expect(Post::query()->count())->toBe($count);
});

it('AC-10: accepts an explicit slug of 200 characters', function () {
    expect(kontenSave(['slug' => str_repeat('a', 200)])->slug)->toBe(str_repeat('a', 200));
});

it('AC-10: keeps generated slugs within the column length', function () {
    $title = str_repeat('kata ', 50);

    expect(strlen($title))->toBeLessThanOrEqual(255);

    $first = kontenSave(['title' => $title]);
    $second = kontenSave(['title' => $title]);

    expect(strlen($first->slug))->toBeLessThanOrEqual(200)->and(strlen($second->slug))->toBeLessThanOrEqual(200)
        ->and($second->slug)->not->toBe($first->slug)->and($second->slug)->toEndWith('-2');
});

it('AC-11: normalizes an explicit slug', function () {
    expect(kontenSave(['slug' => 'Profil Baru!'])->slug)->toBe('profil-baru');
});

it('AC-11: rejects an explicit slug used by another post, including soft-deleted ones', function () {
    kontenPost(['title' => 'Lama', 'slug' => 'dipakai']);
    kontenPost(['title' => 'Terhapus', 'slug' => 'terhapus'])->delete();
    $count = Post::withTrashed()->count();

    kontenAssertRejected(fn () => kontenSave(['slug' => 'dipakai']), 'slug_taken', 'slug');
    kontenAssertRejected(fn () => kontenSave(['slug' => 'terhapus']), 'slug_taken', 'slug');

    expect(Post::withTrashed()->count())->toBe($count);
});

it('AC-11: rejects an explicit slug without letters or digits', function () {
    kontenAssertRejected(fn () => kontenSave(['slug' => '!!!']), 'slug_invalid', 'slug');

    expect(Post::query()->count())->toBe(0);
});

it('AC-11: lets a post keep its own slug on update', function () {
    $post = kontenSave(['slug' => 'milik-sendiri']);

    $updated = kontenSave(['slug' => 'milik-sendiri', 'title' => 'Judul Baru'], post: $post);

    expect($updated->id)->toBe($post->id)->and($updated->fresh())->slug->toBe('milik-sendiri')->title->toBe('Judul Baru');
});

it('AC-12: rejects invalid input without writing a row', function (array $overrides, string $reason, string $field) {
    $category = kontenCategory('album', 'Galeri');
    $overrides = array_map(fn (mixed $value): mixed => $value === ':album-category' ? $category->id : $value, $overrides);

    kontenAssertRejected(fn () => kontenSave($overrides), $reason, $field);

    expect(Post::withTrashed()->count())->toBe(0);
})->with([
    'unknown type' => [['type' => 'artikel'], 'invalid_type', 'type'],
    'blank title' => [['title' => '   '], 'title_required', 'title'],
    'title too long' => [['title' => str_repeat('a', 256)], 'too_long', 'title'],
    'unknown status' => [['status' => 'publish'], 'invalid_status', 'status'],
    'album category' => [['category_id' => ':album-category'], 'invalid_category', 'category_id'],
    'unknown category' => [['category_id' => 9999], 'invalid_category', 'category_id'],
    'unknown unit' => [['unit_id' => 9999], 'invalid_unit', 'unit_id'],
    'excerpt too long' => [['excerpt' => str_repeat('a', 256)], 'too_long', 'excerpt'],
    'excerpt too long after stripping tags' => [['excerpt' => '<b>'.str_repeat('a', 256).'</b>'], 'too_long', 'excerpt'],
    'relative date besok' => [['published_at' => 'besok'], 'published_at_invalid', 'published_at'],
    'relative date tomorrow' => [['published_at' => 'tomorrow'], 'published_at_invalid', 'published_at'],
    'relative date next monday' => [['published_at' => 'next monday'], 'published_at_invalid', 'published_at'],
    'impossible date' => [['published_at' => '2026-13-45 08:00'], 'published_at_invalid', 'published_at'],
]);

it('AC-12: accepts an excerpt of exactly 255 characters', function () {
    expect(kontenSave(['excerpt' => str_repeat('a', 255)])->fresh()->excerpt)->toHaveLength(255);
});

it('AC-12: accepts published_at in the datetime picker formats', function (string $input) {
    $post = kontenSave(['status' => 'terbit', 'published_at' => $input]);

    expect($post->fresh()->published_at->format('Y-m-d H:i:s'))->toBe('2026-10-20 08:00:00');
})->with(['with seconds' => '2026-10-20 08:00:00', 'without seconds' => '2026-10-20 08:00']);

it('AC-12: accepts a Carbon instance for published_at', function () {
    $post = kontenSave(['status' => 'terbit', 'published_at' => CarbonImmutable::parse('2026-10-20 08:00', 'Asia/Jakarta')]);

    expect($post->fresh()->published_at->format('Y-m-d H:i'))->toBe('2026-10-20 08:00');
});

it('AC-13: sanitizes the body before saving', function () {
    $post = kontenSave(['body' => '<p>Halo</p><script>alert(1)</script><img src="x" onerror="alert(1)"><a href="javascript:alert(1)">klik</a><iframe src="https://evil.test"></iframe>']);

    expect($post->fresh()->body)->toContain('<p>Halo</p>')->toContain('klik')
        ->not->toContain('<script')->not->toContain('onerror')->not->toContain('javascript:')->not->toContain('<iframe');
});

it('AC-13: rejects a blank body', function (string $body) {
    kontenAssertRejected(fn () => kontenSave(['body' => $body]), 'body_required', 'body');

    expect(Post::query()->count())->toBe(0);
})->with(['spaces' => '<p> </p>', 'break' => '<p><br></p>', 'empty' => '']);

it('AC-13: rejects a body over 500000 bytes', function () {
    kontenAssertRejected(fn () => kontenSave(['body' => '<p>'.str_repeat('a', 500001 - 7).'</p>']), 'too_long', 'body');
    kontenAssertRejected(fn () => kontenSave(['body' => str_repeat('a', 500001)]), 'too_long', 'body');

    expect(Post::query()->count())->toBe(0);
});

it('AC-13a: counts bytes, not characters, for the body limit', function () {
    $body = kontenArabicBody(250000);

    expect(strlen($body))->toBe(500007)->and(mb_strlen($body))->toBe(250007);

    kontenAssertRejected(fn () => kontenSave(['body' => $body]), 'too_long', 'body');

    expect(Post::query()->count())->toBe(0);
});

it('AC-13a: saves a multibyte body below the limit without truncating it', function () {
    $post = kontenSave(['body' => kontenArabicBody(200000)]);

    expect(strip_tags($post->fresh()->body))->toBe(str_repeat('ب', 200000));
});

it('AC-13b: rejects data media sources in the body', function (string $body) {
    kontenAssertRejected(fn () => kontenSave(['body' => $body]), 'media_source_invalid', 'body');

    expect(Post::query()->count())->toBe(0);
})->with([
    'svg' => '<p>x</p><img src="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=">',
    'png' => '<p>x</p><img src="data:image/png;base64,iVBORw0KGgo=">',
    'srcset' => '<p>x</p><img src="/a.jpg" srcset="/a.jpg 1x, data:image/png;base64,AAA 2x">',
]);

it('AC-13b: keeps http, https and relative images in the body', function (string $src) {
    $post = kontenSave(['body' => "<p>x</p><img src=\"{$src}\" alt=\"a\">"]);

    expect($post->fresh()->body)->toContain("src=\"{$src}\"")->not->toContain('src="data:');
})->with(['https' => 'https://example.test/a.jpg', 'relative' => '/storage/a.jpg']);

it('AC-13b: drops data urls from links instead of rejecting', function () {
    $post = kontenSave(['body' => '<p>x</p><a href="data:text/html;base64,AAA">x</a>']);

    expect($post->fresh()->body)->not->toContain('data:');
});

it('AC-13c: keeps style and class attributes', function () {
    $post = kontenSave(['body' => '<p style="color: red" class="lead">x</p>']);

    expect($post->fresh()->body)->toContain('style="color: red"')->toContain('class="lead"');
});

it('AC-14: normalizes supported youtube urls', function (string $input) {
    $post = kontenSave(['youtube_url' => $input])->fresh();

    expect($post->youtube_url)->toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ')
        ->and($post->youtubeId())->toBe('dQw4w9WgXcQ');
})->with([
    'short link' => 'https://youtu.be/dQw4w9WgXcQ?si=abc',
    'watch with time' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10',
    'shorts' => 'https://m.youtube.com/shorts/dQw4w9WgXcQ',
    'embed' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
]);

it('AC-14: rejects invalid youtube urls', function (string $input) {
    kontenAssertRejected(fn () => kontenSave(['youtube_url' => $input]), 'youtube_invalid', 'youtube_url');

    expect(Post::query()->count())->toBe(0);
})->with([
    'vimeo' => 'https://vimeo.com/123',
    'short id' => 'https://www.youtube.com/watch?v=abc',
    'javascript' => 'javascript:alert(1)',
    'lookalike host' => 'https://youtube.com.evil.test/watch?v=dQw4w9WgXcQ',
]);

it('AC-14: stores no youtube url and no id when the field is blank', function () {
    $post = kontenSave(['youtube_url' => '  '])->fresh();

    expect($post->youtube_url)->toBeNull()->and($post->youtubeId())->toBeNull();
});

it('AC-15: stores a cover image on the public disk under a random name', function () {
    $post = kontenSave(['cover' => kontenImage('foto asli.jpg', 500)])->fresh();

    expect($post->cover_path)->toStartWith('posts/covers/')->toEndWith('.jpg')->not->toContain('foto')
        ->and(Storage::disk('public')->exists($post->cover_path))->toBeTrue()
        ->and(kontenFiles('private'))->toBe([])
        ->and($post->coverUrl())->toBe(Storage::disk('public')->url($post->cover_path));
});

it('AC-15: maps the cover extension from the detected type', function () {
    $post = kontenSave(['cover' => UploadedFile::fake()->image('a.png', 100, 100)->size(100)])->fresh();

    expect($post->cover_path)->toEndWith('.png');
});

it('AC-15: returns no cover url without a cover', function () {
    expect(kontenSave()->coverUrl())->toBeNull();
});

it('AC-15: rejects covers that are not jpg or png images or are too big', function (Closure $cover) {
    kontenAssertRejected(fn () => kontenSave(['cover' => $cover()]), 'image_invalid', 'cover');

    expect(Post::query()->count())->toBe(0)->and(kontenFiles())->toBe([]);
})->with([
    'pdf' => fn () => fn () => kontenPdf(),
    'svg' => fn () => fn () => UploadedFile::fake()->create('a.svg', 10, 'image/svg+xml'),
    'webp' => fn () => fn () => UploadedFile::fake()->create('a.webp', 10, 'image/webp'),
    'over 2 MB' => fn () => fn () => kontenImage('a.jpg', 2049),
]);

it('AC-15: accepts a cover of exactly 2048 KB', function () {
    $post = kontenSave(['cover' => kontenImage('a.jpg', 2048)]);

    expect($post->fresh()->cover_path)->not->toBeNull();
});

it('AC-15: detects the cover type from the file content, not the name', function () {
    $fake = pendaftaranRealFile('menyamar.jpg', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

    kontenAssertRejected(fn () => kontenSave(['cover' => $fake]), 'image_invalid', 'cover');

    expect(Post::query()->count())->toBe(0)->and(kontenFiles())->toBe([]);
});

it('AC-16: replaces the cover and deletes the old file', function () {
    $post = kontenSave(['cover' => kontenImage('a.jpg')])->fresh();
    $old = $post->cover_path;

    $updated = kontenSave(['cover' => kontenImage('b.jpg')], post: $post)->fresh();

    expect($updated->cover_path)->not->toBe($old)->toStartWith('posts/covers/')
        ->and(Storage::disk('public')->exists($old))->toBeFalse()
        ->and(Storage::disk('public')->exists($updated->cover_path))->toBeTrue()
        ->and(kontenFiles())->toHaveCount(1);
});

it('AC-16: removes the cover on request', function () {
    $post = kontenSave(['cover' => kontenImage()])->fresh();
    $old = $post->cover_path;

    kontenSave(['remove_cover' => true], post: $post);

    expect($post->fresh()->cover_path)->toBeNull()->and(Storage::disk('public')->exists($old))->toBeFalse();
});

it('AC-16: keeps the cover when an update neither replaces nor removes it', function () {
    $post = kontenSave(['cover' => kontenImage()])->fresh();

    kontenSave(['title' => 'Judul lain'], post: $post);

    expect($post->fresh()->cover_path)->toBe($post->cover_path)->and(Storage::disk('public')->exists($post->cover_path))->toBeTrue();
});

it('AC-16: lets a new cover win over remove_cover', function () {
    $post = kontenSave(['cover' => kontenImage('a.jpg')])->fresh();

    kontenSave(['cover' => kontenImage('b.jpg'), 'remove_cover' => true], post: $post);

    expect($post->fresh()->cover_path)->not->toBeNull()->and(kontenFiles())->toHaveCount(1);
});

it('AC-16: keeps the soft-deleted post cover file', function () {
    $post = kontenSave(['cover' => kontenImage()])->fresh();

    app(DeletePost::class)->handle($post);

    expect($post->fresh()->trashed())->toBeTrue()->and(Storage::disk('public')->exists($post->cover_path))->toBeTrue();
});

it('AC-16: leaves the old cover and stores no new file when validation fails on update', function () {
    $post = kontenSave(['cover' => kontenImage('a.jpg')])->fresh();
    $old = $post->cover_path;

    kontenAssertRejected(fn () => kontenSave(['title' => '', 'cover' => kontenImage('b.jpg')], post: $post), 'title_required', 'title');

    expect($post->fresh()->cover_path)->toBe($old)->and(kontenFiles())->toBe([$old]);
});

it('AC-16: leaves the old cover when the new cover is invalid', function () {
    $post = kontenSave(['cover' => kontenImage('a.jpg')])->fresh();
    $old = $post->cover_path;

    kontenAssertRejected(fn () => kontenSave(['cover' => kontenPdf()], post: $post), 'image_invalid', 'cover');

    expect($post->fresh()->cover_path)->toBe($old)->and(kontenFiles())->toBe([$old]);
});

it('AC-17: fills published_at with now when publishing without a date', function () {
    $post = kontenSave(['status' => 'terbit']);

    expect($post->fresh()->published_at->format('Y-m-d H:i'))->toBe('2026-10-15 10:00');
});

it('AC-17: keeps a scheduled published_at as given', function () {
    $post = kontenSave(['status' => 'terbit', 'published_at' => '2026-10-20 08:00']);

    expect($post->fresh()->published_at->format('Y-m-d H:i'))->toBe('2026-10-20 08:00');
});

it('AC-17: keeps the stored published_at when the field is cleared on an already published post', function () {
    $post = kontenSave(['status' => 'terbit', 'published_at' => '2026-10-01 07:00']);

    kontenSave(['status' => 'terbit', 'published_at' => null], post: $post);

    expect($post->fresh()->published_at->format('Y-m-d H:i'))->toBe('2026-10-01 07:00');

    kontenSave(['status' => 'terbit', 'published_at' => ''], post: $post);

    expect($post->fresh()->published_at->format('Y-m-d H:i'))->toBe('2026-10-01 07:00');
});

it('AC-17: publishes a draft that has no date with now', function () {
    $post = kontenSave(['status' => 'draft']);

    kontenSave(['status' => 'terbit'], post: $post);

    expect($post->fresh()->published_at->format('Y-m-d H:i'))->toBe('2026-10-15 10:00');
});

it('AC-17: stores a draft with a date as given and keeps published_at null for plain drafts', function () {
    $dated = kontenSave(['status' => 'draft', 'published_at' => '2026-10-25 09:00']);
    $plain = kontenSave(['status' => 'draft', 'title' => 'Draft polos']);

    expect($dated->fresh()->published_at->format('Y-m-d H:i'))->toBe('2026-10-25 09:00')
        ->and($plain->fresh()->published_at)->toBeNull();
});

it('AC-17: keeps the original author when another user edits the post', function () {
    $post = kontenSave();
    $other = kontenUser('super-admin');

    kontenSave(['title' => 'Diubah admin lain'], $other, $post);

    expect($post->fresh())->author_id->toBe($this->editor->id)->title->toBe('Diubah admin lain');
});

it('AC-17: does not touch achievement_id or meta', function () {
    $post = kontenSave();
    // F015: achievement_id kini punya FK, jadi memakai baris prestasi sungguhan.
    $first = prestasiRawAchievement($this->editor->id);
    $second = prestasiRawAchievement($this->editor->id);
    $post->forceFill(['achievement_id' => $first, 'meta' => ['kunci' => 'nilai']])->save();

    kontenSave(['title' => 'Diubah', 'achievement_id' => $second, 'meta' => ['x' => 1]], post: $post);

    expect($post->fresh())->achievement_id->toBe($first)->meta->toBe(['kunci' => 'nilai']);
});

it('AC-21: soft deletes and restores through the actions', function () {
    $post = kontenSave();

    app(DeletePost::class)->handle($post);

    expect(Post::query()->count())->toBe(0)->and(Post::withTrashed()->count())->toBe(1);

    app(RestorePost::class)->handle($post->fresh());

    expect(Post::query()->count())->toBe(1);
});
