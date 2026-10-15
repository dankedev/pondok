<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DormitorySupervisor;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DormitorySupervisorPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:dormitory-supervisor');
    }

    public function view(AuthUser $authUser, DormitorySupervisor $dormitorySupervisor): bool
    {
        return $authUser->can('view:dormitory-supervisor');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:dormitory-supervisor');
    }

    public function update(AuthUser $authUser, DormitorySupervisor $dormitorySupervisor): bool
    {
        return $authUser->can('update:dormitory-supervisor');
    }

    public function delete(AuthUser $authUser, DormitorySupervisor $dormitorySupervisor): bool
    {
        return $authUser->can('delete:dormitory-supervisor');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:dormitory-supervisor');
    }
}
