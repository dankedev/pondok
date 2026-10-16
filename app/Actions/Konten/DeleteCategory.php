<?php

namespace App\Actions\Konten;

use App\Actions\Konten\Concerns\ValidatesContentInput;
use App\Models\Category;

/**
 * Hapus kategori yang tidak dipakai post (termasuk yang terhapus) maupun album.
 */
class DeleteCategory
{
    use ValidatesContentInput;

    public function handle(Category $category): void
    {
        if ($category->isInUse()) {
            $this->fail('category', 'category_in_use');
        }

        $category->delete();
    }
}
