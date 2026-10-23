<?php

namespace App\Actions\Perizinan;

use App\Actions\Perizinan\Concerns\TransitionsLeaveRequest;
use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;

/**
 * Petugas pos mencatat santri berizin `disetujui` keluar dari pondok.
 */
class CheckOutLeaveRequest
{
    use TransitionsLeaveRequest;

    public function handle(LeaveRequest $request, User $actor): LeaveRequest
    {
        $this->authorize($actor->can('check-in-out:leave-request'));
        $this->ensureStatus($request, [LeaveRequestStatus::Approved]);
        $this->authorize($request->isVisibleTo($actor));

        return $this->transition($request, $actor, [LeaveRequestStatus::Approved], LeaveRequestStatus::Out, [
            'departed_at' => now(),
            'checked_out_by' => $actor->id,
        ]);
    }
}
