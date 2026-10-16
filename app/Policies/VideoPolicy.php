<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Video;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class VideoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:video');
    }

    public function view(AuthUser $authUser, Video $video): bool
    {
        return $authUser->can('view:video');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:video');
    }

    public function update(AuthUser $authUser, Video $video): bool
    {
        return $authUser->can('update:video');
    }

    public function delete(AuthUser $authUser, Video $video): bool
    {
        return $authUser->can('delete:video');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:video');
    }
}
