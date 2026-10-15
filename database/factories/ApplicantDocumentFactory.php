<?php

namespace Database\Factories;

use App\Models\AdmissionRequirement;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Berkas pendaftar `menunggu` tanpa file di disk (test menulis file sendiri bila perlu).
 *
 * @extends Factory<ApplicantDocument>
 */
class ApplicantDocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'applicant_id' => Applicant::factory(),
            'admission_requirement_id' => AdmissionRequirement::factory(),
            'file_path' => fn (array $attributes): string => 'psb/applicants/'.$attributes['applicant_id'].'/'.fake()->uuid().'.pdf',
            'original_name' => 'berkas.pdf',
            'mime_type' => 'application/pdf',
            'size_kb' => 100,
            'status' => ApplicantDocument::STATUS_PENDING,
        ];
    }

    public function valid(): static
    {
        return $this->state(['status' => ApplicantDocument::STATUS_VALID, 'verified_at' => now()]);
    }

    public function rejected(string $note): static
    {
        return $this->state(['status' => ApplicantDocument::STATUS_REJECTED, 'note' => $note, 'verified_at' => now()]);
    }
}
