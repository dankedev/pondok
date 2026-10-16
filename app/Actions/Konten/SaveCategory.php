<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ValidatesContentInput;
use App\Models\Category;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Membuat atau mengubah kategori konten. Nama dan slug unik per type; type
 * kategori yang sedang dipakai tidak bisa diubah.
 */
class SaveCategory
{
    use ValidatesContentInput;

    public function __construct(private readonly GenerateSlug $slugs) {}

    /**
     * @param  array{type?: mixed, name?: mixed, slug?: mixed}  $data
     */
    public function handle(array $data, ?Category $category = null): Category
    {
        $type = $data['type'] ?? $category?->type;

        if (! is_string($type) || ! in_array($type, Category::TYPES, true)) {
            $this->fail('type', 'invalid_type');
        }

        if ($category !== null && $type !== $category->type && $category->isInUse()) {
            $this->fail('type', 'category_type_locked');
        }

        $name = $this->requiredText($data['name'] ?? null, 'name', Category::NAME_MAX_LENGTH, 'name_required');

        $nameTaken = Category::query()
            ->ofType($type)
            ->where('name', $name)
            ->when($category?->exists, fn ($query) => $query->whereKeyNot($category->getKey()))
            ->exists();

        if ($nameTaken) {
            $this->fail('name', 'category_name_taken');
        }

        $explicit = is_scalar($data['slug'] ?? null) ? (string) $data['slug'] : null;
        $slug = $this->slugs->handle(Category::class, $name, $explicit, Category::SLUG_MAX_LENGTH, $category, ['type' => $type]);

        $category ??= new Category;

        try {
            $category->fill(['type' => $type, 'name' => $name, 'slug' => $slug])->save();
        } catch (UniqueConstraintViolationException) {
            $this->fail('slug', 'slug_taken');
        }

        return $category;
    }
}
