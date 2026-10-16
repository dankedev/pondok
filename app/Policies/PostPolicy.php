<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Post;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PostPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:post');
    }

    public function view(AuthUser $authUser, Post $post): bool
    {
        return $authUser->can('view:post');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:post');
    }

    public function update(AuthUser $authUser, Post $post): bool
    {
        return $authUser->can('update:post');
    }

    public function delete(AuthUser $authUser, Post $post): bool
    {
        return $authUser->can('delete:post');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:post');
    }

    public function restore(AuthUser $authUser, Post $post): bool
    {
        return $authUser->can('restore:post');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore-any:post');
    }
}
