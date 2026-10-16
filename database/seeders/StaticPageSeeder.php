<?php

namespace Database\Seeders;

use App\Models\StaticPage;
use Illuminate\Database\Seeder;

class StaticPageSeeder extends Seeder
{
    /**
     * Tiga halaman statis tetap. firstOrCreate agar seed ulang tidak menimpa isi yang sudah diubah.
     */
    public function run(): void
    {
        foreach (StaticPage::SLUGS as $slug) {
            StaticPage::query()->firstOrCreate(['slug' => $slug], [
                'title' => __("pondok.static_page.defaults.{$slug}"),
                'body' => '',
            ]);
        }
    }
}
