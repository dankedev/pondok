<?php

namespace Database\Seeders;

use App\Models\StaticPage;
use Illuminate\Database\Seeder;

class StaticPageSeeder extends Seeder
{
    /**
     * Tiga halaman awal (F037, F048): profil dan visi-misi menunggu diisi admin (draf),
     * kontak bertemplate `contact` dan terbit tetapi baru tampil bila profil lembaga punya
     * data kontak. Kolom: status, template.
     */
    private const PAGES = [
        'profil' => [StaticPage::STATUS_DRAFT, StaticPage::TEMPLATE_DEFAULT],
        'visi-misi' => [StaticPage::STATUS_DRAFT, StaticPage::TEMPLATE_DEFAULT],
        'kontak' => [StaticPage::STATUS_PUBLISHED, StaticPage::TEMPLATE_CONTACT],
    ];

    /**
     * Tiga halaman awal. firstOrCreate agar seed ulang tidak menimpa isi atau status yang sudah diubah.
     */
    public function run(): void
    {
        foreach (self::PAGES as $slug => [$status, $template]) {
            StaticPage::query()->firstOrCreate(['slug' => $slug], [
                'title' => __("pondok.static_page.defaults.{$slug}"),
                'body' => '',
                'status' => $status,
                'template' => $template,
            ]);
        }
    }
}
