<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TahfidzTarget;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class TahfidzTargetPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:tahfidz-target');
    }

    public function view(AuthUser $authUser, TahfidzTarget $tahfidzTarget): bool
    {
        return $authUser->can('view:tahfidz-target');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:tahfidz-target');
    }

    public function update(AuthUser $authUser, TahfidzTarget $tahfidzTarget): bool
    {
        return $authUser->can('update:tahfidz-target');
    }

    public function delete(AuthUser $authUser, TahfidzTarget $tahfidzTarget): bool
    {
        return $authUser->can('delete:tahfidz-target');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:tahfidz-target');
    }
}
