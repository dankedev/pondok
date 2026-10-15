<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FeeType;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class FeeTypePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:fee-type');
    }

    public function view(AuthUser $authUser, FeeType $feeType): bool
    {
        return $authUser->can('view:fee-type');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:fee-type');
    }

    public function update(AuthUser $authUser, FeeType $feeType): bool
    {
        return $authUser->can('update:fee-type');
    }

    public function delete(AuthUser $authUser, FeeType $feeType): bool
    {
        return $authUser->can('delete:fee-type');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:fee-type');
    }
}
