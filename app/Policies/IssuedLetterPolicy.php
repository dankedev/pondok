<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\IssuedLetter;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class IssuedLetterPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:issued-letter');
    }

    public function view(AuthUser $authUser, IssuedLetter $issuedLetter): bool
    {
        return $authUser->can('view:issued-letter');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:issued-letter');
    }

    public function update(AuthUser $authUser, IssuedLetter $issuedLetter): bool
    {
        return $authUser->can('update:issued-letter');
    }

    public function delete(AuthUser $authUser, IssuedLetter $issuedLetter): bool
    {
        return $authUser->can('delete:issued-letter');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:issued-letter');
    }

    /**
     * Membatalkan surat `terbit` (status `batal`); syarat data dicek `CancelIssuedLetter`.
     */
    public function cancel(AuthUser $authUser, IssuedLetter $issuedLetter): bool
    {
        return $authUser->can('cancel:issued-letter') && $issuedLetter->isCancellable();
    }

    /**
     * Mengatur format dan kode penomoran surat (`letters.*`).
     */
    public function configureNumbering(AuthUser $authUser): bool
    {
        return $authUser->can('configure-numbering:issued-letter');
    }
}
