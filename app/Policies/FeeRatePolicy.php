<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FeeRate;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class FeeRatePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:fee-rate');
    }

    public function view(AuthUser $authUser, FeeRate $feeRate): bool
    {
        return $authUser->can('view:fee-rate');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:fee-rate');
    }

    public function update(AuthUser $authUser, FeeRate $feeRate): bool
    {
        return $authUser->can('update:fee-rate');
    }

    public function delete(AuthUser $authUser, FeeRate $feeRate): bool
    {
        return $authUser->can('delete:fee-rate');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:fee-rate');
    }
}
