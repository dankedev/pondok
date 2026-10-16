<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Achievement;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Student;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Gate;

class DocumentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:document');
    }

    /**
     * Melihat baris dokumen: permission dan jenis pemilik yang boleh (`Document::visibleOwnerTypesFor`).
     */
    public function view(AuthUser $authUser, Document $document): bool
    {
        return $authUser->can('view:document') && $this->seesOwner($authUser, $document);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:document');
    }

    public function update(AuthUser $authUser, Document $document): bool
    {
        return $authUser->can('update:document');
    }

    public function delete(AuthUser $authUser, Document $document): bool
    {
        return $authUser->can('delete:document');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:document');
    }

    /**
     * Menandai dokumen current `valid` atau `ditolak`; verifikator harus bisa membuka berkasnya.
     * Syarat data lain dicek `VerifyDocument`.
     */
    public function verify(AuthUser $authUser, Document $document): bool
    {
        return $authUser->can('verify:document')
            && $document->isCurrent()
            && $this->download($authUser, $document);
    }

    /**
     * Mengunduh berkas dan melihat nomor lengkap: jenis rahasia butuh `view-confidential:document`.
     */
    public function download(AuthUser $authUser, Document $document): bool
    {
        return $this->view($authUser, $document)
            && (! $document->isConfidential() || $this->viewConfidential($authUser));
    }

    /**
     * Membuka berkas dan nomor dokumen yang jenisnya rahasia.
     */
    public function viewConfidential(AuthUser $authUser): bool
    {
        return $authUser->can('view-confidential:document');
    }

    /**
     * Unggah atau versi baru untuk satu pemilik. Prestasi: siapa pun yang boleh mengubah
     * prestasinya (F015), tanpa `create:document`. Pemilik lain: `create:document` dan
     * santri tidak diarsipkan (aturan F013).
     */
    public function createFor(AuthUser $authUser, Model $owner): bool
    {
        if ($owner instanceof Achievement) {
            return Gate::forUser($authUser)->allows('update', $owner);
        }

        return $this->create($authUser) && ! ($owner instanceof Student && $owner->trashed());
    }

    /**
     * Jenis dokumen yang boleh dipilih saat unggah: jenis rahasia butuh `view-confidential:document`.
     * Dengan `$owner`, hak unggah mengikuti `createFor`.
     */
    public function uploadType(AuthUser $authUser, DocumentType $documentType, ?Model $owner = null): bool
    {
        return ($owner === null ? $this->create($authUser) : $this->createFor($authUser, $owner))
            && (! $documentType->is_confidential || $this->viewConfidential($authUser));
    }

    private function seesOwner(AuthUser $authUser, Document $document): bool
    {
        return $authUser instanceof User && Document::userSeesOwnerType($authUser, $document->ownerAlias());
    }
}
