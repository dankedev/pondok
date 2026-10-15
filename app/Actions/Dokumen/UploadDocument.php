<?php

namespace App\Actions\Dokumen;

use App\Actions\Dokumen\Concerns\StoresDocuments;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Mengunggah dokumen baru untuk santri, wali, atau staff.
 *
 * Selalu membuat baris baru berstatus `menunggu` (beberapa dokumen dengan
 * jenis sama boleh berdampingan); mengganti dokumen lewat `ReplaceDocument`.
 * Berkas disimpan di disk `private` dengan nama acak. Activity `dokumen`
 * dicatat tanpa nomor, path, maupun nama berkas.
 */
class UploadDocument
{
    use StoresDocuments;

    /**
     * @param  array{document_type_id?: int|string|null, file?: mixed, number?: string|null, issued_on?: string|null, expires_on?: string|null}  $data
     */
    public function handle(Model $documentable, array $data, User $uploader): Document
    {
        $alias = $this->ensureOwnerAllowed($documentable);
        $type = $this->documentType($data['document_type_id'] ?? null, $alias);
        $input = $this->validatedFileData($data, $type);

        return $this->storeDocument($alias, $documentable->getKey(), $input, $uploader, $type->id, function (array $attributes) use ($uploader, $type): Document {
            $document = new Document;
            $document->forceFill($attributes)->save();

            activity('dokumen')
                ->performedOn($document)
                ->causedBy($uploader)
                ->event('uploaded')
                ->withProperties([...$this->logProperties($document, $type), 'status' => $document->status])
                ->log('uploaded');

            return $document;
        });
    }

    private function documentType(mixed $value, string $alias): DocumentType
    {
        $type = filled($value) && is_numeric($value) ? DocumentType::query()->find((int) $value) : null;

        if ($type === null) {
            $this->fail('document_type_id', 'document_type_not_found');
        }

        if (! $type->is_active) {
            $this->fail('document_type_id', 'document_type_inactive');
        }

        if ((Document::OWNER_TO_MORPH[$type->owner] ?? null) !== $alias) {
            $this->fail('document_type_id', 'document_type_owner_mismatch');
        }

        return $type;
    }
}
