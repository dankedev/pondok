<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TahfidzExam;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Ujian tahfidz (F023): tidak diubah, koreksi = hapus lalu catat ulang. Per record butuh permission
 * dan halaqahnya harus ada di `Halaqah::visibleTo()`.
 */
class TahfidzExamPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:tahfidz-exam');
    }

    public function view(AuthUser $authUser, TahfidzExam $tahfidzExam): bool
    {
        return $authUser->can('view:tahfidz-exam') && $this->sees($authUser, $tahfidzExam);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:tahfidz-exam');
    }

    public function update(AuthUser $authUser, TahfidzExam $tahfidzExam): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, TahfidzExam $tahfidzExam): bool
    {
        return $authUser->can('delete:tahfidz-exam') && $this->sees($authUser, $tahfidzExam);
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    private function sees(AuthUser $authUser, TahfidzExam $tahfidzExam): bool
    {
        return $authUser instanceof User && $tahfidzExam->isVisibleTo($authUser);
    }
}
