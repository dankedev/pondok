<?php

namespace App\Actions\Perizinan;

use App\Actions\Perizinan\Concerns\GuardsPerizinan;
use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveRequestVia;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Mengajukan izin santri aktif yang terlihat oleh pengaju (`Student::visibleStudentsFor`). Izin mulai
 * `diajukan`; penyetujunya dihitung `ResolveLeaveApprover`. Santri hanya boleh punya satu izin berjalan.
 */
class SubmitLeaveRequest
{
    use GuardsPerizinan;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data): LeaveRequest
    {
        $this->authorize($actor->can('create', LeaveRequest::class));

        $max = LeaveRequest::TEXT_MAX_LENGTH;

        $valid = Validator::make($data, [
            'student_id' => ['required', 'integer'],
            'leave_type_id' => ['required', 'integer'],
            'requested_via' => ['required', Rule::enum(LeaveRequestVia::class)],
            'reason' => ['required', 'string', "max:{$max}"],
            'planned_departure' => ['required', 'date'],
            'return_due' => ['required', 'date', 'after:planned_departure'],
            'pickup_name' => ['nullable', 'string', 'max:255'],
            'pickup_phone' => ['nullable', 'string', 'max:30'],
        ], [], __('pondok.leave_request.fields'))->validate();

        $isAvailable = Student::visibleStudentsFor($actor)
            ->whereKey((int) $valid['student_id'])
            ->where('status', Student::STATUS_ACTIVE)
            ->exists();

        if (! $isAvailable) {
            $this->fail('student_id', 'student_unavailable');
        }

        $type = LeaveType::query()->active()->find((int) $valid['leave_type_id']);

        if (! $type instanceof LeaveType) {
            $this->fail('leave_type_id', 'type_unavailable');
        }

        $departure = CarbonImmutable::parse($valid['planned_departure']);
        $due = CarbonImmutable::parse($valid['return_due']);

        if ($type->max_hours !== null && $departure->diffInMinutes($due) > $type->max_hours * 60) {
            $this->fail('return_due', 'too_long', ['hours' => $type->max_hours]);
        }

        if ($type->requires_guardian && blank($valid['pickup_name'] ?? null)) {
            $this->fail('pickup_name', 'pickup_required');
        }

        $hasOngoing = LeaveRequest::withoutGlobalScopes()
            ->where('student_id', (int) $valid['student_id'])
            ->whereIn('status', LeaveRequestStatus::ongoingValues())
            ->exists();

        if ($hasOngoing) {
            $this->fail('student_id', 'ongoing');
        }

        return DB::transaction(function () use ($actor, $valid, $type, $departure, $due): LeaveRequest {
            $request = new LeaveRequest;
            $request->forceFill([
                'student_id' => (int) $valid['student_id'],
                'leave_type_id' => $type->id,
                'requested_via' => $valid['requested_via'],
                'requested_by' => $actor->id,
                'reason' => $valid['reason'],
                'planned_departure' => $departure,
                'return_due' => $due,
                'pickup_name' => filled($valid['pickup_name'] ?? null) ? $valid['pickup_name'] : null,
                'pickup_phone' => filled($valid['pickup_phone'] ?? null) ? $valid['pickup_phone'] : null,
                'status' => LeaveRequestStatus::Submitted,
            ])->save();

            $this->log($request, $actor, 'created', [
                'student_id' => $request->student_id,
                'leave_type_id' => $request->leave_type_id,
                'to' => LeaveRequestStatus::Submitted->value,
            ]);

            return $request;
        });
    }
}
