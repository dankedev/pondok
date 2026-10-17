<?php

use App\Models\Post;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->f = prestasiFixture();
});

/**
 * Instance migration F015 untuk `posts` (dimuat dari file; tidak bergantung pada urutan migration lain).
 */
function prestasiPostsMigration(): object
{
    $files = glob(database_path('migrations/*_add_achievement_foreign_key_to_posts_table.php'));

    expect($files)->toHaveCount(1, 'Migration *_add_achievement_foreign_key_to_posts_table.php belum ada');

    return require $files[0];
}

it('AC-3: links posts to achievements and keeps posts indexes', function () {
    $keys = collect(Schema::getForeignKeys('posts'));
    $indexes = collect(Schema::getIndexes('posts'));
    $find = fn (string $column) => $keys->first(fn (array $key): bool => $key['columns'] === [$column]);

    expect($find('achievement_id')['foreign_table'] ?? null)->toBe('achievements')
        ->and(strtolower($find('achievement_id')['on_delete']))->toBe('restrict')
        ->and(strtolower($find('category_id')['on_delete']))->toBe('restrict')
        ->and(strtolower($find('unit_id')['on_delete']))->toBe('restrict')
        ->and(strtolower($find('author_id')['on_delete']))->toBe('set null')
        ->and($indexes->where('unique', true)->pluck('columns')->all())->toContain(['slug'])->toContain(['achievement_id'])
        ->and($indexes->where('unique', false)->pluck('columns')->all())->toContain(['status', 'published_at'])->not->toContain(['type'])->not->toContain(['achievement_id'])
        ->and(Schema::hasColumns('posts', ['deleted_at', 'meta', 'cover_path']))->toBeTrue();
});

it('AC-3: keeps soft delete, the status default and nullable achievement_id after the rebuild', function () {
    $post = kontenPost();
    $post->delete();

    expect(Post::query()->count())->toBe(0)
        ->and(Post::withTrashed()->count())->toBe(1);

    DB::table('posts')->insert(['title' => 'Tanpa status', 'slug' => 'tanpa-status', 'body' => '<p>x</p>', 'created_at' => now(), 'updated_at' => now()]);

    expect(DB::table('posts')->where('slug', 'tanpa-status')->value('status'))->toBe('draft')
        ->and(DB::table('posts')->where('slug', 'tanpa-status')->value('achievement_id'))->toBeNull()
        ->and(collect(Schema::getColumns('posts'))->firstWhere('name', 'achievement_id')['nullable'])->toBeTrue();
});

it('AC-3: allows one post per achievement including soft deleted posts', function () {
    $f = $this->f;
    $achievement = prestasiCreate($f, [$f->s2]);
    $first = kontenPost(['achievement_id' => $achievement->id]);
    $first->delete();

    expect(fn () => kontenPost(['achievement_id' => $achievement->id]))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::table('achievements')->where('id', $achievement->id)->delete())->toThrow(QueryException::class);

    kontenPost();
    kontenPost();

    expect(Post::query()->whereNull('achievement_id')->count())->toBe(2);
});

it('AC-3: keeps slug unique after the rebuild', function () {
    kontenPost(['title' => 'Satu', 'slug' => 'sama']);

    expect(fn () => kontenPost(['title' => 'Dua', 'slug' => 'sama']))->toThrow(UniqueConstraintViolationException::class);
});

it('AC-4: clears orphan achievement ids before adding the foreign key', function () {
    $f = $this->f;
    $migration = prestasiPostsMigration();
    $migration->down();

    $kept = prestasiCreate($f, [$f->s2]);
    $orphan = kontenPost(['achievement_id' => 999]);
    $linked = kontenPost(['achievement_id' => $kept->id]);
    $plain = kontenPost();

    $migration->up();

    expect($orphan->fresh()->achievement_id)->toBeNull()
        ->and($linked->fresh()->achievement_id)->toBe($kept->id)
        ->and($plain->fresh()->achievement_id)->toBeNull();
});

it('AC-4: clears every achievement id when achievements is empty', function () {
    $migration = prestasiPostsMigration();
    $migration->down();

    $a = kontenPost(['achievement_id' => 5]);
    $b = kontenPost(['achievement_id' => 6]);
    DB::table('achievements')->delete();

    $migration->up();

    expect($a->fresh()->achievement_id)->toBeNull()->and($b->fresh()->achievement_id)->toBeNull();
});

it('AC-4: restores a plain index on down', function () {
    prestasiPostsMigration()->down();

    $indexes = collect(Schema::getIndexes('posts'));

    expect($indexes->where('unique', false)->pluck('columns')->all())->toContain(['achievement_id'])
        ->and($indexes->where('unique', true)->pluck('columns')->all())->not->toContain(['achievement_id'])
        ->and(collect(Schema::getForeignKeys('posts'))->contains(fn (array $key): bool => $key['columns'] === ['achievement_id']))->toBeFalse();
});
