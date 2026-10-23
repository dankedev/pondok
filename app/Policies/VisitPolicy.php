<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Visit;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Kunjungan wali (F024), dalam cakupan jenjang lewat santri. Tidak dihapus.
 */
class VisitPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:visit');
    }

    public function view(AuthUser $authUser, Visit $visit): bool
    {
        return $authUser->can('view:visit') && $this->sees($authUser, $visit);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:visit');
    }

    public function update(AuthUser $authUser, Visit $visit): bool
    {
        return $authUser->can('update:visit') && $this->sees($authUser, $visit);
    }

    public function delete(AuthUser $authUser, Visit $visit): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    private function sees(AuthUser $authUser, Visit $visit): bool
    {
        return $authUser instanceof User && $visit->isVisibleTo($authUser);
    }
}
