<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FeeOverride;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class FeeOverridePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:fee-override');
    }

    public function view(AuthUser $authUser, FeeOverride $feeOverride): bool
    {
        return $authUser->can('view:fee-override');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:fee-override');
    }

    public function update(AuthUser $authUser, FeeOverride $feeOverride): bool
    {
        return $authUser->can('update:fee-override');
    }

    public function delete(AuthUser $authUser, FeeOverride $feeOverride): bool
    {
        return $authUser->can('delete:fee-override');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:fee-override');
    }
}
