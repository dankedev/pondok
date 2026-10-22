<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MemorizationRecord;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Setoran hafalan (F023): tidak diubah, koreksi = hapus lalu catat ulang. Per record butuh permission
 * dan halaqahnya harus ada di `Halaqah::visibleTo()`.
 */
class MemorizationRecordPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:memorization-record');
    }

    public function view(AuthUser $authUser, MemorizationRecord $memorizationRecord): bool
    {
        return $authUser->can('view:memorization-record') && $this->sees($authUser, $memorizationRecord);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:memorization-record');
    }

    public function update(AuthUser $authUser, MemorizationRecord $memorizationRecord): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, MemorizationRecord $memorizationRecord): bool
    {
        return $authUser->can('delete:memorization-record') && $this->sees($authUser, $memorizationRecord);
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    private function sees(AuthUser $authUser, MemorizationRecord $memorizationRecord): bool
    {
        return $authUser instanceof User && $memorizationRecord->isVisibleTo($authUser);
    }
}
