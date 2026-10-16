<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ValidatesContentInput;
use App\Models\Album;
use App\Models\Category;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Membuat atau mengubah album galeri. Kategori wajib bertipe `album`;
 * deskripsi disimpan sebagai teks polos.
 */
class SaveAlbum
{
    use ValidatesContentInput;

    public const TITLE_MAX_LENGTH = 255;

    public const DESCRIPTION_MAX_LENGTH = 2000;

    public function __construct(private readonly GenerateSlug $slugs) {}

    /**
     * @param  array{title?: mixed, slug?: mixed, category_id?: mixed, description?: mixed}  $data
     */
    public function handle(array $data, ?Album $album = null): Album
    {
        $title = $this->requiredText($data['title'] ?? null, 'title', self::TITLE_MAX_LENGTH);
        $explicit = is_scalar($data['slug'] ?? null) ? (string) $data['slug'] : null;
        $slug = $this->slugs->handle(Album::class, $title, $explicit, Album::SLUG_MAX_LENGTH, $album);

        $categoryId = blank($data['category_id'] ?? null) ? null : Category::query()
            ->ofType(Category::TYPE_ALBUM)
            ->whereKey($data['category_id'])
            ->value('id');

        if ($categoryId === null) {
            $this->fail('category_id', 'invalid_category');
        }

        $description = $this->plainText($data['description'] ?? null, 'description', self::DESCRIPTION_MAX_LENGTH);

        $album ??= new Album;

        try {
            $album->fill([
                'title' => $title,
                'slug' => $slug,
                'category_id' => (int) $categoryId,
                'description' => $description,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            $this->fail('slug', 'slug_taken');
        }

        return $album;
    }
}
