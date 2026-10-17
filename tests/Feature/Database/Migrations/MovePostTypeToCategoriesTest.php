<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| F049 AC-1 sampai AC-3. Kontrak: migrasi `2026_10_22_000000_move_post_type_to_categories.php` (anonymous class,
| `up()` dan `down()`), ditulis dengan `DB::table`. `up()` mengisi kategori dari `posts.type` lalu menghapus kolom
| dan indeks `type`; `down()` menambah `type` string(10) default 'berita' tanpa indeks.
| Skema test sudah migrasi penuh, jadi helper `legacyPostsType()` memasang kembali kolom lama sebelum `up()`.
*/

function postTypeMigration(): object
{
    return require database_path('migrations/2026_10_22_000000_move_post_type_to_categories.php');
}

/**
 * Pulihkan skema lama (kolom `type` + indeks) agar `up()` bisa dijalankan pada data lama.
 */
function legacyPostsType(): void
{
    if (Schema::hasColumn('posts', 'type')) {
        return;
    }

    Schema::table('posts', function ($table) {
        $table->string('type', 10)->default('berita');
        $table->index('type');
    });
}

function legacyPost(string $type, string $slug, array $extra = []): int
{
    return DB::table('posts')->insertGetId([
        'type' => $type, 'title' => $slug, 'slug' => $slug, 'body' => '<p>x</p>', 'status' => 'draft',
        'created_at' => now(), 'updated_at' => now(), ...$extra,
    ]);
}

function postCategoryId(int $postId): ?int
{
    return DB::table('posts')->where('id', $postId)->value('category_id');
}

it('AC-1: drops the type column and its index and down() restores a column defaulting to berita', function () {
    $migration = postTypeMigration();
    legacyPostsType();

    $migration->up();

    expect(Schema::hasColumn('posts', 'type'))->toBeFalse()
        ->and(collect(Schema::getIndexes('posts'))->pluck('columns')->all())->not->toContain(['type'])
        ->and(Schema::hasColumns('posts', ['id', 'category_id', 'unit_id', 'author_id', 'title', 'slug', 'excerpt', 'body', 'cover_path', 'youtube_url', 'achievement_id', 'meta', 'status', 'published_at', 'deleted_at']))->toBeTrue();

    $migration->down();
    $id = DB::table('posts')->insertGetId(['title' => 'Baru', 'slug' => 'baru', 'body' => '<p>x</p>', 'created_at' => now(), 'updated_at' => now()]);

    expect(Schema::hasColumn('posts', 'type'))->toBeTrue()
        ->and(DB::table('posts')->where('id', $id)->value('type'))->toBe('berita');
});

it('AC-2: puts kegiatan and prestasi posts without a category into their own post categories and leaves berita alone', function () {
    $migration = postTypeMigration();
    legacyPostsType();
    $kegiatan = legacyPost('kegiatan', 'kegiatan-satu');
    $prestasi = legacyPost('prestasi', 'prestasi-satu');
    $berita = legacyPost('berita', 'berita-satu');
    $trashed = legacyPost('kegiatan', 'kegiatan-hapus', ['deleted_at' => now()]);
    $before = DB::table('posts')->orderBy('id')->pluck('slug')->all();

    $migration->up();

    $categories = DB::table('categories')->where('type', 'post')->get()->keyBy('name');

    expect($categories->keys()->sort()->values()->all())->toBe(['Kegiatan', 'Prestasi'])
        ->and([$categories['Kegiatan']->slug, $categories['Prestasi']->slug])->toBe(['kegiatan', 'prestasi'])
        ->and(postCategoryId($kegiatan))->toBe($categories['Kegiatan']->id)
        ->and(postCategoryId($trashed))->toBe($categories['Kegiatan']->id)
        ->and(postCategoryId($prestasi))->toBe($categories['Prestasi']->id)
        ->and(postCategoryId($berita))->toBeNull()
        ->and(DB::table('posts')->orderBy('id')->pluck('slug')->all())->toBe($before);
});

it('AC-3: keeps posts that already have a category and reuses existing categories matched by name or slug in any case', function () {
    $migration = postTypeMigration();
    legacyPostsType();
    $own = DB::table('categories')->insertGetId(['type' => 'post', 'name' => 'Pengumuman', 'slug' => 'pengumuman', 'created_at' => now(), 'updated_at' => now()]);
    $byName = DB::table('categories')->insertGetId(['type' => 'post', 'name' => 'KEGIATAN', 'slug' => 'agenda', 'created_at' => now(), 'updated_at' => now()]);
    $bySlug = DB::table('categories')->insertGetId(['type' => 'post', 'name' => 'Capaian', 'slug' => 'prestasi', 'created_at' => now(), 'updated_at' => now()]);
    $album = DB::table('categories')->insertGetId(['type' => 'album', 'name' => 'Kegiatan', 'slug' => 'kegiatan', 'created_at' => now(), 'updated_at' => now()]);
    $owned = legacyPost('prestasi', 'sudah-punya', ['category_id' => $own]);
    $kegiatan = legacyPost('kegiatan', 'kegiatan-baru');
    $prestasi = legacyPost('prestasi', 'prestasi-baru');

    $migration->up();

    expect(postCategoryId($owned))->toBe($own)
        ->and(postCategoryId($kegiatan))->toBe($byName)
        ->and(postCategoryId($prestasi))->toBe($bySlug)
        ->and(DB::table('categories')->where('type', 'post')->count())->toBe(3)
        ->and(DB::table('categories')->where('type', 'album')->pluck('id')->all())->toBe([$album]);
});

it('AC-3: creates no category when no kegiatan or prestasi post needs one', function () {
    $migration = postTypeMigration();
    legacyPostsType();
    legacyPost('berita', 'berita-saja');
    legacyPost('kegiatan', 'kegiatan-berkategori', ['category_id' => DB::table('categories')->insertGetId(['type' => 'post', 'name' => 'Lain', 'slug' => 'lain', 'created_at' => now(), 'updated_at' => now()])]);

    $migration->up();

    expect(DB::table('categories')->pluck('name')->all())->toBe(['Lain']);
});

it('VERIFY: creates a post category even when an album category already owns the slug, and never duplicates on a second type', function () {
    $migration = postTypeMigration();
    legacyPostsType();
    $album = DB::table('categories')->insertGetId(['type' => 'album', 'name' => 'Prestasi', 'slug' => 'prestasi', 'created_at' => now(), 'updated_at' => now()]);
    $one = legacyPost('prestasi', 'p-satu');
    $two = legacyPost('prestasi', 'p-dua', ['deleted_at' => now()]);

    $migration->up();

    $post = DB::table('categories')->where('type', 'post')->get();

    expect($post)->toHaveCount(1)
        ->and($post->first()->id)->not->toBe($album)
        ->and(postCategoryId($one))->toBe($post->first()->id)
        ->and(postCategoryId($two))->toBe($post->first()->id);
});
