<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Category;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:category');
    }

    public function view(AuthUser $authUser, Category $category): bool
    {
        return $authUser->can('view:category');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:category');
    }

    public function update(AuthUser $authUser, Category $category): bool
    {
        return $authUser->can('update:category');
    }

    public function delete(AuthUser $authUser, Category $category): bool
    {
        return $authUser->can('delete:category');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:category');
    }
}
