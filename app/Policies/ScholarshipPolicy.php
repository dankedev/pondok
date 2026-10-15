<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Scholarship;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ScholarshipPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:scholarship');
    }

    public function view(AuthUser $authUser, Scholarship $scholarship): bool
    {
        return $authUser->can('view:scholarship');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:scholarship');
    }

    public function update(AuthUser $authUser, Scholarship $scholarship): bool
    {
        return $authUser->can('update:scholarship');
    }

    public function delete(AuthUser $authUser, Scholarship $scholarship): bool
    {
        return $authUser->can('delete:scholarship');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:scholarship');
    }
}
