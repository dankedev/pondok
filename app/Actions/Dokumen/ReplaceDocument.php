<?php

namespace App\Actions\Dokumen;

use App\Actions\Dokumen\Concerns\StoresDocuments;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Mengunggah versi baru dokumen. Jenis dan pemilik diwarisi; versi lama hanya
 * ditandai `replaced_by_id` (status, nomor, berkas, dan verifikator tetap,
 * berkas lama tidak dihapus). Jenis yang sudah nonaktif tetap boleh.
 */
class ReplaceDocument
{
    use StoresDocuments;

    /**
     * @param  array{file?: mixed, number?: string|null, issued_on?: string|null, expires_on?: string|null}  $data
     */
    public function handle(Document $current, array $data, User $uploader): Document
    {
        $current = Document::withoutUnitScope()->with('documentType')->find($current->getKey());

        if ($current === null || ! $current->isCurrent()) {
            $this->fail('document', 'already_replaced');
        }

        $alias = $this->ensureOwnerAllowed($this->owner($current));
        $type = $current->documentType;
        $input = $this->validatedFileData($data, $type);

        return $this->storeDocument($alias, $current->documentable_id, $input, $uploader, $type->id, function (array $attributes) use ($current, $uploader, $type): Document {
            $locked = Document::withoutUnitScope()->lockForUpdate()->find($current->getKey());

            if ($locked === null || ! $locked->isCurrent()) {
                $this->fail('document', 'already_replaced');
            }

            $document = new Document;
            $document->forceFill($attributes)->save();
            $locked->forceFill(['replaced_by_id' => $document->id])->save();

            activity('dokumen')
                ->performedOn($document)
                ->causedBy($uploader)
                ->event('replaced')
                ->withProperties([...$this->logProperties($document, $type), 'replaces_id' => $locked->id])
                ->log('replaced');

            return $document;
        });
    }

    /**
     * Pemilik dokumen tanpa global scope (termasuk santri terarsip), agar pemeriksaan pemilik memberi alasan yang tepat.
     */
    private function owner(Document $document): Model
    {
        $class = Relation::getMorphedModel($document->documentable_type);
        $owner = $class === null ? null : $class::query()->withoutGlobalScopes()->find($document->documentable_id);

        if (! $owner instanceof Model) {
            $this->fail('documentable', 'owner_not_found');
        }

        return $owner;
    }
}
