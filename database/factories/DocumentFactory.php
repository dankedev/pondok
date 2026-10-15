<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Guardian;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Dokumen santri `menunggu` tanpa berkas di disk (test menulis berkas sendiri bila perlu).
 *
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'document_type_id' => DocumentType::factory(),
            'documentable_type' => 'student',
            'documentable_id' => Student::factory(),
            'file_path' => fn (array $attributes): string => Document::DIRECTORY.'/'.$attributes['documentable_type'].'/'.$attributes['documentable_id'].'/'.fake()->uuid().'.pdf',
            'original_name' => 'dokumen.pdf',
            'mime_type' => 'application/pdf',
            'size_kb' => 100,
            'status' => Document::STATUS_PENDING,
            'uploaded_by' => User::factory(),
        ];
    }

    public function valid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Document::STATUS_VALID,
            'verified_by' => $attributes['uploaded_by'],
            'verified_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Document::STATUS_REJECTED,
            'verified_by' => $attributes['uploaded_by'],
            'verified_at' => now(),
        ]);
    }

    public function forStudent(?Student $student = null): static
    {
        return $this->state(['documentable_type' => 'student', 'documentable_id' => $student ?? Student::factory()]);
    }

    public function forGuardian(?Guardian $guardian = null): static
    {
        return $this->state(['documentable_type' => 'guardian', 'documentable_id' => $guardian ?? Guardian::factory()]);
    }

    public function forStaff(?Staff $staff = null): static
    {
        return $this->state(['documentable_type' => 'staff', 'documentable_id' => $staff ?? Staff::factory()]);
    }

    public function replacedBy(Document $document): static
    {
        return $this->state(['replaced_by_id' => $document->id]);
    }
}
