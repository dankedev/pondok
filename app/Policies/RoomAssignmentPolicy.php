<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RoomAssignment;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class RoomAssignmentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:room-assignment');
    }

    public function view(AuthUser $authUser, RoomAssignment $roomAssignment): bool
    {
        return $authUser->can('view:room-assignment');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:room-assignment');
    }

    public function update(AuthUser $authUser, RoomAssignment $roomAssignment): bool
    {
        return $authUser->can('update:room-assignment');
    }

    public function delete(AuthUser $authUser, RoomAssignment $roomAssignment): bool
    {
        return $authUser->can('delete:room-assignment');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:room-assignment');
    }
}
