<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\HomecomingPeriod;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Periode pulang massal (F024): tidak dihapus karena dirujuk izin.
 */
class HomecomingPeriodPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:homecoming-period');
    }

    public function view(AuthUser $authUser, HomecomingPeriod $homecomingPeriod): bool
    {
        return $authUser->can('view:homecoming-period');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:homecoming-period');
    }

    public function update(AuthUser $authUser, HomecomingPeriod $homecomingPeriod): bool
    {
        return $authUser->can('update:homecoming-period');
    }

    /**
     * Membuat izin pulang massal untuk periode ini (`GenerateHomecomingLeaves`).
     */
    public function generate(AuthUser $authUser, HomecomingPeriod $homecomingPeriod): bool
    {
        return $authUser->can('generate:homecoming-period');
    }

    public function delete(AuthUser $authUser, HomecomingPeriod $homecomingPeriod): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }
}
