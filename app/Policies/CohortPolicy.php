<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cohort;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CohortPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:cohort');
    }

    public function view(AuthUser $authUser, Cohort $cohort): bool
    {
        return $authUser->can('view:cohort');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:cohort');
    }

    public function update(AuthUser $authUser, Cohort $cohort): bool
    {
        return $authUser->can('update:cohort');
    }

    public function delete(AuthUser $authUser, Cohort $cohort): bool
    {
        return $authUser->can('delete:cohort');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:cohort');
    }
}
