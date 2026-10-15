<?php

namespace App\Actions\Dokumen;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Student;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Berkas wajib santri yang belum terpenuhi, dengan alasannya. Daftar kosong
 * berarti lengkap. Konsisten dengan scope `Student::withIncompleteDocuments()`;
 * tidak terpengaruh cakupan jenjang dokumen dan tidak memeriksa status santri.
 */
class CheckDocumentCompleteness
{
    public const REASON_MISSING = 'missing';

    public const REASON_PENDING = 'pending';

    public const REASON_EXPIRED = 'expired';

    public const REASON_REJECTED = 'rejected';

    /**
     * @return list<array{document_type: DocumentType, reason: string}>
     */
    public function handle(Student $student, ?CarbonInterface $today = null): array
    {
        $today ??= today();

        $types = DocumentType::query()
            ->forStudents()
            ->active()
            ->where('is_required', true)
            ->orderBy('name')
            ->get();

        if ($types->isEmpty()) {
            return [];
        }

        $documents = Document::withoutUnitScope()
            ->current()
            ->where('documentable_type', $student->getMorphClass())
            ->where('documentable_id', $student->getKey())
            ->whereIn('document_type_id', $types->modelKeys())
            ->get(['id', 'document_type_id', 'status', 'expires_on'])
            ->groupBy('document_type_id');

        $items = [];

        foreach ($types as $type) {
            $reason = $this->reason($documents->get($type->id, collect()), $today);

            if ($reason !== null) {
                $items[] = ['document_type' => $type, 'reason' => $reason];
            }
        }

        return $items;
    }

    /**
     * @param  Collection<int, Document>  $documents
     */
    private function reason(Collection $documents, CarbonInterface $today): ?string
    {
        $valid = $documents->where('status', Document::STATUS_VALID);

        return match (true) {
            $valid->contains(fn (Document $document): bool => ! $document->isExpired($today)) => null,
            $documents->contains('status', Document::STATUS_PENDING) => self::REASON_PENDING,
            $valid->isNotEmpty() => self::REASON_EXPIRED,
            $documents->contains('status', Document::STATUS_REJECTED) => self::REASON_REJECTED,
            default => self::REASON_MISSING,
        };
    }
}
