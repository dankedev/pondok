<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DiniyahSubject;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Mapel diniyah (F027): tidak dihapus, hanya dinonaktifkan. Cakupan jenjang dari global scope model.
 */
class DiniyahSubjectPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:diniyah-subject');
    }

    public function view(AuthUser $authUser, DiniyahSubject $diniyahSubject): bool
    {
        return $authUser->can('view:diniyah-subject');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:diniyah-subject');
    }

    public function update(AuthUser $authUser, DiniyahSubject $diniyahSubject): bool
    {
        return $authUser->can('update:diniyah-subject');
    }

    public function delete(AuthUser $authUser, DiniyahSubject $diniyahSubject): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }
}
