<?php

declare(strict_types=1);

namespace App\Policies;

use App\Actions\Perizinan\ResolveLeaveApprover;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Izin santri (F024). Per record butuh permission dan izinnya harus terlihat (`LeaveRequest::scopeVisibleTo`).
 * Tidak ada ubah atau hapus: status berubah lewat Action, koreksi = batal lalu ajukan ulang.
 */
class LeaveRequestPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view-any:leave-request');
    }

    public function view(AuthUser $authUser, LeaveRequest $leaveRequest): bool
    {
        return $authUser->can('view:leave-request') && $this->sees($authUser, $leaveRequest);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create:leave-request');
    }

    /**
     * Setujui/tolak: hanya penyetuju izin ini (`ResolveLeaveApprover::canDecide`).
     */
    public function decide(AuthUser $authUser, LeaveRequest $leaveRequest): bool
    {
        return $authUser instanceof User && app(ResolveLeaveApprover::class)->canDecide($authUser, $leaveRequest);
    }

    public function checkInOut(AuthUser $authUser, LeaveRequest $leaveRequest): bool
    {
        return $authUser->can('check-in-out:leave-request') && $this->sees($authUser, $leaveRequest);
    }

    public function cancel(AuthUser $authUser, LeaveRequest $leaveRequest): bool
    {
        return $authUser->can('cancel:leave-request') && $this->sees($authUser, $leaveRequest);
    }

    public function update(AuthUser $authUser, LeaveRequest $leaveRequest): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, LeaveRequest $leaveRequest): bool
    {
        return false;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return false;
    }

    private function sees(AuthUser $authUser, LeaveRequest $leaveRequest): bool
    {
        return $authUser instanceof User && $leaveRequest->isVisibleTo($authUser);
    }
}
