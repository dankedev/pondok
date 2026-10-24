<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DisciplineLevel;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Ambang poin kedisiplinan (F026): hanya label rekap, boleh dihapus.
 */
class DisciplineLevelPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:discipline-level');
    }

    public function view(AuthUser $authUser, DisciplineLevel $disciplineLevel): bool
    {
        return $authUser->can('view:discipline-level');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:discipline-level');
    }

    public function update(AuthUser $authUser, DisciplineLevel $disciplineLevel): bool
    {
        return $authUser->can('update:discipline-level');
    }

    public function delete(AuthUser $authUser, DisciplineLevel $disciplineLevel): bool
    {
        return $authUser->can('delete:discipline-level');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete-any:discipline-level');
    }
}
