<?php

use App\Actions\Konten\DeleteCategory;
use App\Actions\Konten\SaveCategory;
use App\Models\Category;

beforeEach(function () {
    $this->f = kontenBoot();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function kontenSaveCategory(array $overrides = [], ?Category $category = null): Category
{
    return app(SaveCategory::class)->handle(['type' => 'album', 'name' => 'Fasilitas', ...$overrides], $category);
}

it('AC-22: creates an album category with a generated slug', function () {
    $category = kontenSaveCategory()->fresh();

    expect($category)->type->toBe('album')->name->toBe('Fasilitas')->slug->toBe('fasilitas');
});

it('AC-22: accepts a slug of 120 characters and rejects 121', function () {
    expect(kontenSaveCategory(['slug' => str_repeat('a', 120)])->fresh()->slug)->toBe(str_repeat('a', 120));

    kontenAssertRejected(fn () => kontenSaveCategory(['name' => 'Lain', 'slug' => str_repeat('a', 121)]), 'too_long', 'slug');

    expect(Category::query()->count())->toBe(1);
});

it('AC-22: keeps the generated slug within 120 characters for a 100 character name', function () {
    $category = kontenSaveCategory(['name' => str_repeat('k', 100)])->fresh();

    expect(strlen($category->slug))->toBeLessThanOrEqual(120)->and($category->name)->toHaveLength(100);
});

it('AC-22: rejects a name of 101 characters or a blank name', function () {
    kontenAssertRejected(fn () => kontenSaveCategory(['name' => str_repeat('k', 101)]), 'too_long', 'name');
    kontenAssertRejected(fn () => kontenSaveCategory(['name' => '   ']), 'name_required', 'name');

    expect(Category::query()->count())->toBe(0);
});

it('AC-22: rejects a duplicate name within the same type but allows it for the other type', function () {
    kontenSaveCategory();

    kontenAssertRejected(fn () => kontenSaveCategory(), 'category_name_taken', 'name');

    $post = kontenSaveCategory(['type' => 'post']);

    expect($post->fresh())->type->toBe('post')->slug->toBe('fasilitas')
        ->and(Category::query()->count())->toBe(2);
});

it('AC-22: rejects a slug already used within the same type', function () {
    kontenSaveCategory(['slug' => 'dipakai']);

    kontenAssertRejected(fn () => kontenSaveCategory(['name' => 'Lain', 'slug' => 'dipakai']), 'slug_taken', 'slug');

    expect(kontenSaveCategory(['name' => 'Lain', 'slug' => 'dipakai', 'type' => 'post'])->fresh()->slug)->toBe('dipakai');
});

it('AC-22: appends a suffix to a generated slug that is taken', function () {
    kontenCategory('album', 'Lain', 'kegiatan-santri');

    expect(kontenSaveCategory(['name' => 'Kegiatan Santri'])->slug)->toBe('kegiatan-santri-2');
});

it('AC-22: rejects an unknown type', function () {
    kontenAssertRejected(fn () => kontenSaveCategory(['type' => 'video']), 'invalid_type', 'type');

    expect(Category::query()->count())->toBe(0);
});

it('AC-22: locks the type of a category in use', function () {
    $category = kontenCategory('album', 'Terpakai');
    kontenAlbum(['category_id' => $category->id]);

    kontenAssertRejected(fn () => kontenSaveCategory(['type' => 'post', 'name' => 'Terpakai'], $category), 'category_type_locked', 'type');

    expect($category->fresh()->type)->toBe('album');
});

it('AC-22: counts soft-deleted posts as use when locking the type', function () {
    $category = kontenCategory('post', 'Berita lama');
    kontenPost(['category_id' => $category->id])->delete();

    expect($category->isInUse())->toBeTrue();

    kontenAssertRejected(fn () => kontenSaveCategory(['type' => 'album', 'name' => 'Berita lama'], $category), 'category_type_locked', 'type');
});

it('AC-22: allows renaming a category in use and changing the type of an unused one', function () {
    $used = kontenCategory('album', 'Terpakai');
    kontenAlbum(['category_id' => $used->id]);
    $free = kontenCategory('album', 'Bebas');

    kontenSaveCategory(['name' => 'Terpakai Baru'], $used);
    kontenSaveCategory(['type' => 'post', 'name' => 'Bebas'], $free);

    expect($used->fresh()->name)->toBe('Terpakai Baru')->and($free->fresh()->type)->toBe('post');
});

it('AC-23: refuses to delete a category used by a soft-deleted post or an album', function () {
    $byPost = kontenCategory('post', 'Dipakai post');
    kontenPost(['category_id' => $byPost->id])->delete();
    $byAlbum = kontenCategory('album', 'Dipakai album');
    kontenAlbum(['category_id' => $byAlbum->id]);

    kontenAssertRejected(fn () => app(DeleteCategory::class)->handle($byPost), 'category_in_use', 'category');
    kontenAssertRejected(fn () => app(DeleteCategory::class)->handle($byAlbum), 'category_in_use', 'category');

    expect(Category::query()->whereKey([$byPost->id, $byAlbum->id])->count())->toBe(2);
});

it('AC-23: deletes an unused category', function () {
    $free = kontenCategory('album', 'Bebas');

    app(DeleteCategory::class)->handle($free);

    expect(Category::query()->find($free->id))->toBeNull();
});
