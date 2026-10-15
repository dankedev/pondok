<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DocumentType;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DocumentTypePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:document-type');
    }

    public function view(AuthUser $authUser, DocumentType $documentType): bool
    {
        return $authUser->can('view:document-type');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:document-type');
    }

    public function update(AuthUser $authUser, DocumentType $documentType): bool
    {
        return $authUser->can('update:document-type');
    }

    public function delete(AuthUser $authUser, DocumentType $documentType): bool
    {
        return $authUser->can('delete:document-type');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:document-type');
    }
}
