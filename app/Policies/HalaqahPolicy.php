<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Halaqah;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Halaqah (F023): per record butuh permission dan halaqah harus ada di `Halaqah::visibleTo()`.
 */
class HalaqahPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:halaqah');
    }

    public function view(AuthUser $authUser, Halaqah $halaqah): bool
    {
        return $authUser->can('view:halaqah') && $this->sees($authUser, $halaqah);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:halaqah');
    }

    public function update(AuthUser $authUser, Halaqah $halaqah): bool
    {
        return $authUser->can('update:halaqah') && $this->sees($authUser, $halaqah);
    }

    public function delete(AuthUser $authUser, Halaqah $halaqah): bool
    {
        return $authUser->can('delete:halaqah') && $this->sees($authUser, $halaqah);
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:halaqah');
    }

    private function sees(AuthUser $authUser, Halaqah $halaqah): bool
    {
        return $authUser instanceof User && $halaqah->isVisibleTo($authUser);
    }
}
