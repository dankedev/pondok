<?php

namespace Database\Seeders;

use App\Models\StaticPage;
use Illuminate\Database\Seeder;

class StaticPageSeeder extends Seeder
{
    /**
     * Status awal halaman sistem (F037): profil dan visi-misi menunggu diisi admin
     * (draf), kontak terbit tetapi baru tampil bila profil lembaga punya data kontak.
     */
    private const STATUSES = [
        'profil' => StaticPage::STATUS_DRAFT,
        'visi-misi' => StaticPage::STATUS_DRAFT,
        'kontak' => StaticPage::STATUS_PUBLISHED,
    ];

    /**
     * Tiga halaman sistem. firstOrCreate agar seed ulang tidak menimpa isi atau status yang sudah diubah.
     */
    public function run(): void
    {
        foreach (StaticPage::SYSTEM_SLUGS as $slug) {
            StaticPage::query()->firstOrCreate(['slug' => $slug], [
                'title' => __("pondok.static_page.defaults.{$slug}"),
                'body' => '',
                'status' => self::STATUSES[$slug],
            ]);
        }
    }
}
