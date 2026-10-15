<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Dormitory;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DormitoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:dormitory');
    }

    public function view(AuthUser $authUser, Dormitory $dormitory): bool
    {
        return $authUser->can('view:dormitory');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:dormitory');
    }

    public function update(AuthUser $authUser, Dormitory $dormitory): bool
    {
        return $authUser->can('update:dormitory');
    }

    public function delete(AuthUser $authUser, Dormitory $dormitory): bool
    {
        return $authUser->can('delete:dormitory');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:dormitory');
    }
}
