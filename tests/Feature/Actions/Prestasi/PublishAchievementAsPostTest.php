<?php

use App\Actions\Konten\GenerateSlug;
use App\Actions\Prestasi\PublishAchievementAsPost;
use App\Models\Achievement;
use App\Models\Category;
use App\Models\Post;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->f = prestasiFixture();
});

/**
 * Prestasi publik terverifikasi (beregu `s2` ketua dan `s3`) dengan judul dan penyelenggara dari plan.
 *
 * @param  array<string, mixed>  $attributes
 */
function prestasiPublishable(object $f, array $attributes = []): Achievement
{
    return prestasiCreate($f, [[$f->s2, 'Juara 1', 'Ketua tim'], [$f->s3, 'Juara 1']], [
        'verified' => true,
        'public' => true,
        'title' => 'Juara 1 MTQ Tahfidz 10 Juz',
        'organizer' => 'Kemenag Kabupaten',
        ...$attributes,
    ]);
}

it('AC-16: creates a draft post from an achievement', function () {
    $f = $this->f;
    $achievement = prestasiPublishable($f);
    $this->actingAs($f->c);

    $post = app(PublishAchievementAsPost::class)->handle($achievement, $f->c);

    $row = Post::query()->sole();

    expect($post->is($row))->toBeTrue()
        ->and($row->status)->toBe('draft')
        ->and($row->published_at)->toBeNull()
        ->and($row->category->name)->toBe('Prestasi')
        ->and($row->category->type)->toBe('post')
        ->and($row->title)->toBe('Juara 1 MTQ Tahfidz 10 Juz')
        ->and($row->slug)->toBe('juara-1-mtq-tahfidz-10-juz')
        ->and($row->author_id)->toBe($f->c->id)
        ->and($row->achievement_id)->toBe($achievement->id)
        ->and($row->unit_id)->toBe($f->sltp->id)
        ->and($row->excerpt)->toContain('MTQ Tingkat Kabupaten 2026')
        ->toContain(__('pondok.achievement.levels.kabupaten'))
        ->toContain('17 Agu 2026')
        ->not->toContain('Aug')
        ->and($row->body)->toContain('Budi Dua')->toContain('Candra Tiga')->toContain('Ketua tim')
        ->toContain('Kemenag Kabupaten')->toContain('17 Agu 2026')->toContain('<ul>')
        ->and(mb_strlen($row->excerpt))->toBeLessThanOrEqual(255)
        ->and($achievement->fresh()->post->is($row))->toBeTrue();
});

it('AC-16: formats the excerpt date with the Indonesian month', function () {
    $f = $this->f;
    $achievement = prestasiPublishable($f, ['title' => 'Juara 2 Lomba Pidato', 'achieved_on' => '2026-10-03']);
    $this->actingAs($f->c);

    $post = app(PublishAchievementAsPost::class)->handle($achievement, $f->c);

    expect($post->excerpt)->toContain('3 Okt 2026')->not->toContain('Oct')
        ->and($post->body)->toContain('3 Okt 2026');
});

it('AC-16: lists participants by name and writes the draft even for an unscoped content admin', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s3, $f->s2], ['verified' => true, 'public' => true, 'title' => 'Lomba urut']);
    $f->s3->delete();
    $this->actingAs($f->c);

    $post = app(PublishAchievementAsPost::class)->handle($achievement, $f->c);

    expect(strpos($post->body, 'Budi Dua'))->toBeLessThan(strpos($post->body, 'Candra Tiga'));
});

it('AC-17: uses a general unit and escapes names', function () {
    $f = $this->f;
    $f->s1->forceFill(['name' => '<b>Ali</b>'])->save();
    $achievement = prestasiCreate($f, [$f->s1, $f->s2], ['verified' => true, 'public' => true, 'title' => 'Lomba lintas jenjang']);
    $this->actingAs($f->c);

    $post = app(PublishAchievementAsPost::class)->handle($achievement, $f->c);

    expect($post->unit_id)->toBeNull()
        ->and($post->fresh()->body)->not->toContain('<b>')->toContain('Ali')->toContain('Budi Dua');
});

it('AC-17: includes the whole team even when the publisher is unit scoped', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s1, $f->s2], ['verified' => true, 'public' => true, 'title' => 'Tim lintas']);
    $publisher = keuanganUser('admin-konten', ['SLTP']);
    $this->actingAs($publisher);

    $post = app(PublishAchievementAsPost::class)->handle($achievement, $publisher);

    expect($post->body)->toContain('Ahmad Satu')->toContain('Budi Dua')
        ->and($post->unit_id)->toBeNull();
});

it('AC-18: rejects unpublishable achievements (:key)', function (string $key, string $reason) {
    $f = $this->f;
    $achievement = match ($key) {
        'private' => prestasiPublishable($f, ['is_public' => false]),
        'unverified' => prestasiPublishable($f, ['verified_by' => null]),
        'has post' => (function () use ($f) {
            $achievement = prestasiPublishable($f);
            kontenPost(['achievement_id' => $achievement->id]);

            return $achievement;
        })(),
        'has deleted post' => (function () use ($f) {
            $achievement = prestasiPublishable($f);
            kontenPost(['achievement_id' => $achievement->id])->delete();

            return $achievement;
        })(),
    };
    $before = Post::withTrashed()->count();
    $this->actingAs($f->c);

    prestasiAssertRejected(fn () => app(PublishAchievementAsPost::class)->handle($achievement, $f->c), $reason, 'achievement');

    expect(Post::withTrashed()->count())->toBe($before);
})->with([
    'private' => ['private', 'not_public'],
    'unverified' => ['unverified', 'not_verified'],
    'has post' => ['has post', 'already_published'],
    'has deleted post' => ['has deleted post', 'already_published'],
]);

it('AC-18: does not fail when the slug is taken by another post', function () {
    $f = $this->f;
    kontenPost(['title' => 'Juara 1 MTQ Tahfidz 10 Juz']);
    $achievement = prestasiPublishable($f);
    $this->actingAs($f->c);

    $post = app(PublishAchievementAsPost::class)->handle($achievement, $f->c);

    expect($post->slug)->toBe('juara-1-mtq-tahfidz-10-juz-2');
});

it('AC-18: maps a duplicate post race to already_published', function () {
    $f = $this->f;
    $achievement = prestasiPublishable($f);
    $this->actingAs($f->c);

    Post::saving(function (Post $post) use ($achievement): void {
        if ($post->exists && $post->achievement_id === $achievement->id) {
            DB::table('posts')->insert([
                'title' => 'Pemenang balapan', 'slug' => 'pemenang-balapan', 'body' => '<p>x</p>',
                'achievement_id' => $achievement->id, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    prestasiAssertRejected(fn () => app(PublishAchievementAsPost::class)->handle($achievement, $f->c), 'already_published', 'achievement');

    expect(Post::withTrashed()->count())->toBe(1)
        ->and(Post::withTrashed()->sole()->slug)->toBe('pemenang-balapan');
});

it('AC-18: does not map a slug conflict inside SavePost to already_published', function () {
    $f = $this->f;
    kontenPost(['title' => 'Slug terpakai', 'slug' => 'terpakai']);
    $this->mock(GenerateSlug::class, fn ($mock) => $mock->shouldReceive('handle')->andReturn('terpakai'));
    $achievement = prestasiPublishable($f);
    $this->actingAs($f->c);

    kontenAssertRejected(fn () => app(PublishAchievementAsPost::class)->handle($achievement, $f->c), 'slug_taken', 'slug');

    expect(Post::withTrashed()->count())->toBe(1)
        ->and($achievement->fresh()->post)->toBeNull();
});

it('AC-5: creates the Prestasi category once and reuses it for later achievements', function () {
    $f = $this->f;
    $this->actingAs($f->c);

    $first = app(PublishAchievementAsPost::class)->handle(prestasiPublishable($f), $f->c);
    $second = app(PublishAchievementAsPost::class)->handle(prestasiPublishable($f, ['title' => 'Juara 2 Lomba Pidato']), $f->c);

    expect(Category::query()->where('type', 'post')->where('name', 'Prestasi')->count())->toBe(1)
        ->and($first->category_id)->toBe($second->category_id)
        ->and(Category::query()->where('type', 'post')->count())->toBe(1);
});

it('AC-5: reuses an existing Prestasi category, including one whose slug differs', function (string $slug) {
    $f = $this->f;
    $existing = kontenCategory('post', 'Prestasi', $slug);
    $this->actingAs($f->c);

    $post = app(PublishAchievementAsPost::class)->handle(prestasiPublishable($f), $f->c);

    expect($post->category_id)->toBe($existing->id)->and(Category::query()->where('type', 'post')->count())->toBe(1);
})->with(['same slug' => ['prestasi'], 'different slug' => ['juara-juara']]);

it('AC-5: reuses a category that holds the slug prestasi under another name', function () {
    $f = $this->f;
    $existing = kontenCategory('post', 'Capaian Santri', 'prestasi');
    $this->actingAs($f->c);

    $post = app(PublishAchievementAsPost::class)->handle(prestasiPublishable($f), $f->c);

    expect($post->category_id)->toBe($existing->id)->and(Category::query()->where('type', 'post')->count())->toBe(1);
});
