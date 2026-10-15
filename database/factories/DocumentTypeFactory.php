<?php

namespace Database\Factories;

use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Jenis dokumen santri aktif tanpa sifat khusus.
 *
 * @extends Factory<DocumentType>
 */
class DocumentTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->regexify('DOK_[A-Z]{4}[0-9]{3}'),
            'name' => 'Dokumen '.fake()->unique()->word(),
            'owner' => DocumentType::OWNER_STUDENT,
            'is_required' => false,
            'has_expiry' => false,
            'is_confidential' => false,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
