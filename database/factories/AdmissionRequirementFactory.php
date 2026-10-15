<?php

namespace Database\Factories;

use App\Models\AdmissionRequirement;
use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Syarat wajib untuk semua jenjang. Kolom generated `unit_key` tidak pernah ditulis.
 *
 * @extends Factory<AdmissionRequirement>
 */
class AdmissionRequirementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'unit_id' => null,
            'document_type_id' => DocumentType::factory(),
            'name' => 'Berkas '.fake()->unique()->word(),
            'is_required' => true,
            'sort_order' => 0,
        ];
    }
}
