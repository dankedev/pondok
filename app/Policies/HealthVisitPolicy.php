<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\HealthVisit;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Kesehatan santri (F031). Per record butuh permission dan santrinya harus terlihat oleh user
 * (`HealthVisit::visibleStudents`). Hapus satu per satu oleh super-admin; tanpa hapus massal.
 */
class HealthVisitPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:health-visit');
    }

    public function view(AuthUser $authUser, HealthVisit $healthVisit): bool
    {
        return $authUser->can('view:health-visit') && $this->sees($authUser, $healthVisit);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:health-visit');
    }

    public function update(AuthUser $authUser, HealthVisit $healthVisit): bool
    {
        return $authUser->can('update:health-visit') && $this->sees($authUser, $healthVisit);
    }

    public function delete(AuthUser $authUser, HealthVisit $healthVisit): bool
    {
        return $authUser->can('delete:health-visit') && $this->sees($authUser, $healthVisit);
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    private function sees(AuthUser $authUser, HealthVisit $healthVisit): bool
    {
        return $authUser instanceof User && $healthVisit->isVisibleTo($authUser);
    }
}
