<?php

namespace App\Actions\Perizinan;

use App\Actions\Perizinan\Concerns\TransitionsLeaveRequest;
use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;

/**
 * Petugas pos mencatat santri yang sedang `keluar` sudah kembali. Terlambat dihitung dari `returned_at`.
 */
class CheckInLeaveRequest
{
    use TransitionsLeaveRequest;

    public function handle(LeaveRequest $request, User $actor): LeaveRequest
    {
        $this->authorize($actor->can('check-in-out:leave-request'));
        $this->ensureStatus($request, [LeaveRequestStatus::Out]);
        $this->authorize($request->isVisibleTo($actor));

        return $this->transition($request, $actor, [LeaveRequestStatus::Out], LeaveRequestStatus::Returned, [
            'returned_at' => now(),
            'checked_in_by' => $actor->id,
        ]);
    }
}
