<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Album;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AlbumPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:album');
    }

    public function view(AuthUser $authUser, Album $album): bool
    {
        return $authUser->can('view:album');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:album');
    }

    public function update(AuthUser $authUser, Album $album): bool
    {
        return $authUser->can('update:album');
    }

    public function delete(AuthUser $authUser, Album $album): bool
    {
        return $authUser->can('delete:album');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:album');
    }
}
