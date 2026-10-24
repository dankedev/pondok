<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AttendanceSession;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Absensi harian per rombel (F025), dalam `AttendanceSession::visibleClassrooms`. Tidak dihapus.
 */
class AttendanceSessionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:attendance-session');
    }

    public function view(AuthUser $authUser, AttendanceSession $attendanceSession): bool
    {
        return $authUser->can('view:attendance-session') && $this->sees($authUser, $attendanceSession);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:attendance-session');
    }

    public function update(AuthUser $authUser, AttendanceSession $attendanceSession): bool
    {
        return $authUser->can('update:attendance-session') && $this->sees($authUser, $attendanceSession);
    }

    public function delete(AuthUser $authUser, AttendanceSession $attendanceSession): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    private function sees(AuthUser $authUser, AttendanceSession $attendanceSession): bool
    {
        return $authUser instanceof User
            && AttendanceSession::visibleClassrooms($authUser)->whereKey($attendanceSession->classroom_id)->exists();
    }
}
