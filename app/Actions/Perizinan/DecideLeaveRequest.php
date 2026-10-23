<?php

namespace App\Actions\Perizinan;

use App\Actions\Perizinan\Concerns\TransitionsLeaveRequest;
use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * Menyetujui atau menolak izin `diajukan` oleh penyetujunya (`ResolveLeaveApprover::canDecide`).
 * `approved_by`/`approved_at` diisi pemutus, juga saat ditolak. Catatan wajib saat menolak.
 */
class DecideLeaveRequest
{
    use TransitionsLeaveRequest;

    public function __construct(private ResolveLeaveApprover $approvers) {}

    public function handle(LeaveRequest $request, User $actor, bool $approve, ?string $note = null): LeaveRequest
    {
        $this->authorize($actor->can('approve:leave-request') || $actor->can('approve-any:leave-request'));
        $this->ensureStatus($request, [LeaveRequestStatus::Submitted]);
        $this->authorize($this->approvers->canDecide($actor, $request));

        $max = LeaveRequest::TEXT_MAX_LENGTH;

        $valid = Validator::make(['note' => $note], [
            'note' => [$approve ? 'nullable' : 'required', 'string', "max:{$max}"],
        ], [], __('pondok.leave_request.fields'))->validate();

        return $this->transition($request, $actor, [LeaveRequestStatus::Submitted], $approve ? LeaveRequestStatus::Approved : LeaveRequestStatus::Rejected, [
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'note' => filled($valid['note'] ?? null) ? $valid['note'] : null,
        ]);
    }
}
