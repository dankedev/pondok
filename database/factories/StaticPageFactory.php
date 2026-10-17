<?php

namespace Database\Factories;

use App\Models\StaticPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Halaman bebas terbit berisi. Status selalu diisi eksplisit (default kolom DB hanya untuk migrasi).
 *
 * @extends Factory<StaticPage>
 */
class StaticPageFactory extends Factory
{
    public function definition(): array
    {
        $slug = fake()->unique()->regexify('halaman-[a-z]{8}');

        return [
            'slug' => $slug,
            'title' => 'Halaman '.substr($slug, 8),
            'body' => '<p>Isi halaman.</p>',
            'status' => StaticPage::STATUS_PUBLISHED,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => StaticPage::STATUS_DRAFT]);
    }

    public function published(): static
    {
        return $this->state(['status' => StaticPage::STATUS_PUBLISHED]);
    }

    /**
     * Halaman bertemplate kontak (menampilkan kartu kontak lembaga).
     */
    public function contact(): static
    {
        return $this->state(['template' => StaticPage::TEMPLATE_CONTACT]);
    }
}
