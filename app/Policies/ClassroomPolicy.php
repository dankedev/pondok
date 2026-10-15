<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Classroom;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ClassroomPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:classroom');
    }

    public function view(AuthUser $authUser, Classroom $classroom): bool
    {
        return $authUser->can('view:classroom');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:classroom');
    }

    public function update(AuthUser $authUser, Classroom $classroom): bool
    {
        return $authUser->can('update:classroom');
    }

    public function delete(AuthUser $authUser, Classroom $classroom): bool
    {
        return $authUser->can('delete:classroom');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:classroom');
    }
}
