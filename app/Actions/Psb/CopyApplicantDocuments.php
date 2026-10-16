<?php

namespace App\Actions\Psb;

use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\Document;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Menyalin berkas pendaftar berstatus `valid` menjadi dokumen santri atau wali
 * (sesuai `document_types.owner`) saat daftar ulang. File disalin di disk
 * `private`; file sumber dan baris `applicant_documents` tidak diubah.
 *
 * Dilewati: sudah pernah disalin, pemilik selain santri/wali, wali yang sudah
 * punya dokumen current sejenis (dokumen lama tidak disentuh), dan file sumber
 * yang hilang. Gagal menyalin melempar exception agar daftar ulang di-rollback;
 * setiap path salinan dicatat di `$copiedPaths` untuk dihapus pemanggil.
 * Dipanggil di dalam transaksi `ReRegisterApplicant`.
 */
class CopyApplicantDocuments
{
    public const REASON_ALREADY_COPIED = 'already_copied';

    public const REASON_OWNER_NOT_SUPPORTED = 'owner_not_supported';

    public const REASON_OWNER_HAS_CURRENT = 'owner_has_current';

    public const REASON_FILE_MISSING = 'file_missing';

    /**
     * @param  list<string>  $copiedPaths
     * @return array{copied: list<int>, skipped: list<array{applicant_document_id: int, reason: string}>}
     *
     * @throws RuntimeException file gagal disalin
     */
    public function handle(Applicant $applicant, Student $student, Guardian $guardian, User $by, array &$copiedPaths): array
    {
        $result = ['copied' => [], 'skipped' => []];
        $owners = ['student' => $student, 'guardian' => $guardian];

        $sources = ApplicantDocument::withoutGlobalScopes()
            ->with('requirement.documentType')
            ->where('applicant_id', $applicant->id)
            ->where('status', ApplicantDocument::STATUS_VALID)
            ->orderBy('id')
            ->get();

        foreach ($sources as $source) {
            $type = $source->requirement?->documentType;
            $alias = $type === null ? null : (Document::OWNER_TO_MORPH[$type->owner] ?? null);
            $reason = match (true) {
                Document::withoutUnitScope()->where('source_applicant_document_id', $source->id)->exists() => self::REASON_ALREADY_COPIED,
                $alias === null || ! isset($owners[$alias]) => self::REASON_OWNER_NOT_SUPPORTED,
                $alias === 'guardian' && $this->hasCurrent($guardian, $type->id) => self::REASON_OWNER_HAS_CURRENT,
                ! Storage::disk(Document::DISK)->exists($source->file_path) => self::REASON_FILE_MISSING,
                default => null,
            };

            if ($reason !== null) {
                if ($reason === self::REASON_FILE_MISSING) {
                    Log::warning('Berkas pendaftar tidak disalin: file tidak ada', [
                        'registration_no' => $applicant->registration_no,
                        'applicant_document_id' => $source->id,
                    ]);
                }

                $result['skipped'][] = ['applicant_document_id' => $source->id, 'reason' => $reason];

                continue;
            }

            $result['copied'][] = $this->copy($source, $alias, $owners[$alias], $by, $copiedPaths)->id;
        }

        return $result;
    }

    /**
     * @param  list<string>  $copiedPaths
     */
    private function copy(ApplicantDocument $source, string $alias, Model $owner, User $by, array &$copiedPaths): Document
    {
        $extension = Document::EXTENSIONS[$source->mime_type] ?? (pathinfo($source->file_path, PATHINFO_EXTENSION) ?: 'bin');
        $path = Document::DIRECTORY."/{$alias}/{$owner->getKey()}/".Str::random(40).".{$extension}";

        try {
            $copied = Storage::disk(Document::DISK)->copy($source->file_path, $path);
        } catch (Throwable $exception) {
            $this->fail($source, $exception);
        }

        if (! $copied) {
            $this->fail($source);
        }

        $copiedPaths[] = $path;

        $document = Document::query()->create([
            'document_type_id' => $source->requirement->document_type_id,
            'documentable_type' => $alias,
            'documentable_id' => $owner->getKey(),
            'number' => null,
            'issued_on' => null,
            'expires_on' => null,
            'file_path' => $path,
            'original_name' => $source->original_name,
            'mime_type' => $source->mime_type,
            'size_kb' => $source->size_kb,
            'status' => Document::STATUS_VALID,
            'verified_by' => $source->verified_by,
            'verified_at' => $source->verified_at,
            'uploaded_by' => $by->id,
            'source_applicant_document_id' => $source->id,
        ]);

        activity('dokumen')
            ->performedOn($document)
            ->causedBy($by)
            ->event('copied_from_applicant')
            ->withProperties([
                'document_type' => $source->requirement->documentType->code,
                'documentable_type' => $alias,
                'documentable_id' => $owner->getKey(),
                'source_applicant_document_id' => $source->id,
                'status' => Document::STATUS_VALID,
            ])
            ->log(__('pondok.applicant.activity_document_copied'));

        return $document;
    }

    private function hasCurrent(Guardian $guardian, int $documentTypeId): bool
    {
        return Document::withoutUnitScope()
            ->where('documentable_type', 'guardian')
            ->where('documentable_id', $guardian->id)
            ->where('document_type_id', $documentTypeId)
            ->current()
            ->exists();
    }

    private function fail(ApplicantDocument $source, ?Throwable $previous = null): never
    {
        Log::warning('Berkas pendaftar gagal disalin', ['applicant_document_id' => $source->id]);

        throw new RuntimeException(__('pondok.psb.errors.file_copy_failed'), previous: $previous);
    }
}
