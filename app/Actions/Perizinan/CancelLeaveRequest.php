<?php

namespace App\Actions\Perizinan;

use App\Actions\Perizinan\Concerns\TransitionsLeaveRequest;
use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * Membatalkan izin yang belum dipakai keluar (`diajukan`/`disetujui`). Catatan opsional menimpa catatan lama.
 */
class CancelLeaveRequest
{
    use TransitionsLeaveRequest;

    private const CANCELLABLE = [LeaveRequestStatus::Submitted, LeaveRequestStatus::Approved];

    public function handle(LeaveRequest $request, User $actor, ?string $note = null): LeaveRequest
    {
        $this->authorize($actor->can('cancel:leave-request'));
        $this->ensureStatus($request, self::CANCELLABLE);
        $this->authorize($request->isVisibleTo($actor));

        $max = LeaveRequest::TEXT_MAX_LENGTH;

        Validator::make(['note' => $note], [
            'note' => ['nullable', 'string', "max:{$max}"],
        ], [], __('pondok.leave_request.fields'))->validate();

        return $this->transition($request, $actor, self::CANCELLABLE, LeaveRequestStatus::Cancelled, filled($note) ? ['note' => $note] : []);
    }
}
