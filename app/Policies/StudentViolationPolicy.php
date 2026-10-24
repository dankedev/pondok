<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StudentViolation;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Pelanggaran santri (F026). Per record butuh permission dan santrinya harus terlihat
 * (`Student::visibleStudentsFor`). Tidak dihapus: koreksi lewat pembatalan.
 */
class StudentViolationPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:student-violation');
    }

    public function view(AuthUser $authUser, StudentViolation $studentViolation): bool
    {
        return $authUser->can('view:student-violation') && $this->sees($authUser, $studentViolation);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:student-violation');
    }

    public function update(AuthUser $authUser, StudentViolation $studentViolation): bool
    {
        return $authUser->can('update:student-violation') && $this->sees($authUser, $studentViolation);
    }

    public function cancel(AuthUser $authUser, StudentViolation $studentViolation): bool
    {
        return $authUser->can('cancel:student-violation') && $this->sees($authUser, $studentViolation);
    }

    public function delete(AuthUser $authUser, StudentViolation $studentViolation): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    private function sees(AuthUser $authUser, StudentViolation $studentViolation): bool
    {
        return $authUser instanceof User && $studentViolation->isVisibleTo($authUser);
    }
}
