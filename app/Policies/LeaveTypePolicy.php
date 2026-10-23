<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LeaveType;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Jenis izin (F024): dinonaktifkan, tidak dihapus.
 */
class LeaveTypePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:leave-type');
    }

    public function view(AuthUser $authUser, LeaveType $leaveType): bool
    {
        return $authUser->can('view:leave-type');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:leave-type');
    }

    public function update(AuthUser $authUser, LeaveType $leaveType): bool
    {
        return $authUser->can('update:leave-type');
    }

    public function delete(AuthUser $authUser, LeaveType $leaveType): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }
}
