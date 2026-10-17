<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->f = kontenBoot();
});

it('AC-1: creates the content tables with the planned columns', function () {
    $columns = [
        'categories' => ['id', 'type', 'name', 'slug', 'created_at', 'updated_at'],
        'posts' => ['id', 'category_id', 'unit_id', 'author_id', 'type', 'title', 'slug', 'excerpt', 'body', 'cover_path', 'youtube_url', 'achievement_id', 'meta', 'status', 'published_at', 'created_at', 'updated_at', 'deleted_at'],
        'albums' => ['id', 'category_id', 'title', 'slug', 'description', 'created_at', 'updated_at'],
        'photos' => ['id', 'album_id', 'path', 'caption', 'sort_order', 'created_at', 'updated_at'],
        'videos' => ['id', 'title', 'youtube_id', 'description', 'is_featured', 'sort_order', 'created_at', 'updated_at'],
        'pages' => ['id', 'slug', 'title', 'body', 'status', 'template', 'created_at', 'updated_at'],
    ];

    foreach ($columns as $table => $expected) {
        expect(Schema::hasTable($table))->toBeTrue("Tabel {$table} belum ada")
            ->and(Schema::getColumnListing($table))->toEqualCanonicalizing($expected);
    }
});

it('AC-1: soft deletes only posts among the content tables', function () {
    foreach (['categories', 'albums', 'photos', 'videos', 'pages'] as $table) {
        expect(Schema::hasColumn($table, 'deleted_at'))->toBeFalse("{$table} tidak boleh soft delete");
    }

    expect(Schema::hasColumn('posts', 'deleted_at'))->toBeTrue();
});

it('AC-1: enforces unique post slugs including soft-deleted posts', function () {
    kontenPost(['title' => 'Satu', 'slug' => 'sama']);

    expect(fn () => kontenPost(['title' => 'Dua', 'slug' => 'sama']))->toThrow(UniqueConstraintViolationException::class);

    $trashed = kontenPost(['title' => 'Tiga', 'slug' => 'terhapus']);
    $trashed->delete();

    expect(fn () => kontenPost(['title' => 'Empat', 'slug' => 'terhapus']))->toThrow(UniqueConstraintViolationException::class);
});

it('AC-1: enforces unique album slugs', function () {
    kontenAlbum(['title' => 'Satu', 'slug' => 'sama']);

    expect(fn () => kontenAlbum(['title' => 'Dua', 'slug' => 'sama']))->toThrow(UniqueConstraintViolationException::class);
});

it('AC-1: scopes category uniqueness per type', function () {
    kontenCategory('album', 'Fasilitas', 'fasilitas');

    expect(fn () => kontenCategory('album', 'Fasilitas', 'lain'))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => kontenCategory('album', 'Lain', 'fasilitas'))->toThrow(UniqueConstraintViolationException::class);

    kontenCategory('post', 'Fasilitas', 'fasilitas');

    expect(DB::table('categories')->count())->toBe(2);
});

it('AC-1: enforces unique static page slugs', function () {
    DB::table('pages')->insert(['slug' => 'profil', 'title' => 'Profil', 'body' => '', 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => DB::table('pages')->insert(['slug' => 'profil', 'title' => 'Lagi', 'body' => '', 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('AC-2: restricts deleting categories referenced by posts, including soft-deleted ones', function () {
    $category = kontenCategory('post', 'Umum');
    $post = kontenPost(['category_id' => $category->id]);

    expect(fn () => DB::table('categories')->where('id', $category->id)->delete())->toThrow(QueryException::class);

    $post->delete();

    expect(fn () => DB::table('categories')->where('id', $category->id)->delete())->toThrow(QueryException::class)
        ->and(DB::table('categories')->where('id', $category->id)->exists())->toBeTrue();
});

it('AC-2: restricts deleting categories referenced by albums', function () {
    $album = kontenAlbum();

    expect(fn () => DB::table('categories')->where('id', $album->category_id)->delete())->toThrow(QueryException::class);
});

it('AC-2: restricts deleting units referenced by posts', function () {
    kontenPost(['unit_id' => $this->f->sd->id]);

    expect(fn () => DB::table('units')->where('id', $this->f->sd->id)->delete())->toThrow(QueryException::class);
});

it('AC-2: nulls the author when a user is deleted', function () {
    $user = User::factory()->create();
    $post = kontenPost(['author_id' => $user->id]);

    DB::table('users')->where('id', $user->id)->delete();

    expect(DB::table('posts')->where('id', $post->id)->exists())->toBeTrue()
        ->and(DB::table('posts')->where('id', $post->id)->value('author_id'))->toBeNull();
});

it('AC-2: cascades photos when an album is deleted', function () {
    $album = kontenAlbum();
    kontenPhoto($album);
    kontenPhoto($album);
    $other = kontenPhoto(kontenAlbum());

    DB::table('albums')->where('id', $album->id)->delete();

    expect(DB::table('photos')->where('album_id', $album->id)->count())->toBe(0)
        ->and(DB::table('photos')->where('id', $other->id)->exists())->toBeTrue();
});

// F015 menambah FK `achievement_id` ke `achievements` (lihat PostAchievementForeignKeyTest); di sini cukup nullable.
it('AC-2: keeps achievement_id nullable and accepts an existing achievement', function () {
    $plain = kontenPost();
    $linked = kontenPost(['achievement_id' => prestasiRawAchievement()]);

    expect($plain->fresh()->achievement_id)->toBeNull()
        ->and($linked->fresh()->achievement_id)->not->toBeNull();
});
