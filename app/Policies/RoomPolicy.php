<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Room;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class RoomPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:room');
    }

    public function view(AuthUser $authUser, Room $room): bool
    {
        return $authUser->can('view:room');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:room');
    }

    public function update(AuthUser $authUser, Room $room): bool
    {
        return $authUser->can('update:room');
    }

    public function delete(AuthUser $authUser, Room $room): bool
    {
        return $authUser->can('delete:room');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:room');
    }
}
