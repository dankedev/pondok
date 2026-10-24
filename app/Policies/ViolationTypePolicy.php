<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ViolationType;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Jenis pelanggaran (F026): dinonaktifkan, tidak dihapus.
 */
class ViolationTypePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:violation-type');
    }

    public function view(AuthUser $authUser, ViolationType $violationType): bool
    {
        return $authUser->can('view:violation-type');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:violation-type');
    }

    public function update(AuthUser $authUser, ViolationType $violationType): bool
    {
        return $authUser->can('update:violation-type');
    }

    public function delete(AuthUser $authUser, ViolationType $violationType): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }
}
