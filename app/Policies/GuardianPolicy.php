<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Guardian;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class GuardianPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:guardian');
    }

    public function view(AuthUser $authUser, Guardian $guardian): bool
    {
        return $authUser->can('view:guardian');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:guardian');
    }

    public function update(AuthUser $authUser, Guardian $guardian): bool
    {
        return $authUser->can('update:guardian');
    }

    public function delete(AuthUser $authUser, Guardian $guardian): bool
    {
        return $authUser->can('delete:guardian');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:guardian');
    }
}
