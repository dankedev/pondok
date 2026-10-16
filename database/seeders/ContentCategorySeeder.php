<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ContentCategorySeeder extends Seeder
{
    /** Kategori album bawaan (PRD 3.1: galeri fasilitas dan kegiatan). */
    public const ALBUM_CATEGORIES = ['Fasilitas', 'Kegiatan'];

    public function run(): void
    {
        foreach (self::ALBUM_CATEGORIES as $name) {
            Category::query()->firstOrCreate(
                ['type' => Category::TYPE_ALBUM, 'name' => $name],
                ['slug' => Str::slug($name)],
            );
        }
    }
}
