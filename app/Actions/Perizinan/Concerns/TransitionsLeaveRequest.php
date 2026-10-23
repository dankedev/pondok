<?php

namespace App\Actions\Perizinan\Concerns;

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Perpindahan status izin (F024 D4): baris dikunci, status dicek ulang, lalu activity `from`/`to`.
 * Dua petugas yang menekan bersamaan tidak menghasilkan dua transisi.
 */
trait TransitionsLeaveRequest
{
    use GuardsPerizinan;

    /**
     * @param  list<LeaveRequestStatus>  $from
     */
    protected function ensureStatus(LeaveRequest $request, array $from): void
    {
        if (! in_array($request->status, $from, true)) {
            $this->fail('status', 'invalid_transition', ['status' => $request->status?->label() ?? '-']);
        }
    }

    /**
     * @param  list<LeaveRequestStatus>  $from
     * @param  array<string, mixed>  $attributes
     */
    protected function transition(LeaveRequest $request, User $actor, array $from, LeaveRequestStatus $to, array $attributes = []): LeaveRequest
    {
        return DB::transaction(function () use ($request, $actor, $from, $to, $attributes): LeaveRequest {
            $locked = LeaveRequest::withoutGlobalScopes()->lockForUpdate()->findOrFail($request->getKey());
            $this->ensureStatus($locked, $from);

            $previous = $locked->status;
            $locked->forceFill(['status' => $to, ...$attributes])->save();

            $this->log($locked, $actor, $to->value, [
                'student_id' => $locked->student_id,
                'from' => $previous->value,
                'to' => $to->value,
            ]);

            $request->setRawAttributes($locked->getAttributes(), true);

            return $request;
        });
    }
}
