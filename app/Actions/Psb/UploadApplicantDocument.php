<?php

namespace App\Actions\Psb;

use App\Models\AdmissionRequirement;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Unggah berkas pendaftar dari halaman status publik.
 *
 * Hanya pendaftar `diajukan` yang biaya formulirnya lunas, untuk syarat
 * jenjangnya, dan selama berkas lama belum `valid`. Tipe dibaca dari isi file
 * (PDF/JPG/PNG), maksimal 2 MB. File disimpan di disk `private` dengan nama
 * acak dan ekstensi dari tipe isi; unggah ulang menimpa baris dan menghapus
 * file lama setelah commit.
 */
class UploadApplicantDocument
{
    public const MAX_KB = 2048;

    public const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public function handle(Applicant $applicant, AdmissionRequirement $requirement, UploadedFile $file): ApplicantDocument
    {
        if ($applicant->status !== Applicant::STATUS_SUBMITTED) {
            $this->fail('document', 'uploads_closed');
        }

        if (! $applicant->registrationFeeSettled()) {
            $this->fail('document', 'registration_fee_unpaid');
        }

        if (! $applicant->requirements()->whereKey($requirement->id)->exists()) {
            $this->fail('document', 'requirement_not_applicable');
        }

        $document = ApplicantDocument::withoutGlobalScopes()->firstOrNew([
            'applicant_id' => $applicant->id,
            'admission_requirement_id' => $requirement->id,
        ]);

        if ($document->status === ApplicantDocument::STATUS_VALID) {
            $this->fail('document', 'document_already_valid');
        }

        $extension = self::EXTENSIONS[$file->getMimeType()] ?? null;

        if ($extension === null) {
            $this->fail('file', 'file_type_invalid');
        }

        $bytes = (int) $file->getSize();

        if ($bytes > self::MAX_KB * 1024) {
            $this->fail('file', 'file_too_large');
        }

        $oldPath = $document->exists ? $document->file_path : null;
        $path = Storage::disk(ApplicantDocument::DISK)->putFileAs(
            "psb/applicants/{$applicant->id}",
            $file,
            Str::uuid()->toString().".{$extension}",
        );

        try {
            DB::transaction(function () use ($document, $path, $file, $bytes): void {
                $document->forceFill([
                    'file_path' => $path,
                    'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                    'mime_type' => $file->getMimeType(),
                    'size_kb' => (int) ceil($bytes / 1024),
                    'status' => ApplicantDocument::STATUS_PENDING,
                    'note' => null,
                    'verified_by' => null,
                    'verified_at' => null,
                ])->save();
            });
        } catch (Throwable $exception) {
            Storage::disk(ApplicantDocument::DISK)->delete($path);

            throw $exception;
        }

        if ($oldPath !== null && $oldPath !== $path) {
            Storage::disk(ApplicantDocument::DISK)->delete($oldPath);
        }

        return $document;
    }

    private function fail(string $field, string $reason): never
    {
        throw ValidationException::withMessages([
            $field => __("pondok.psb.errors.{$reason}"),
        ]);
    }
}
