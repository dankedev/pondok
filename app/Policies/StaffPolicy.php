<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Staff;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class StaffPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:staff');
    }

    public function view(AuthUser $authUser, Staff $staff): bool
    {
        return $authUser->can('view:staff');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:staff');
    }

    public function update(AuthUser $authUser, Staff $staff): bool
    {
        return $authUser->can('update:staff');
    }

    public function delete(AuthUser $authUser, Staff $staff): bool
    {
        return $authUser->can('delete:staff');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:staff');
    }

    public function import(AuthUser $authUser): bool
    {
        return $authUser->can('import:staff');
    }
}
