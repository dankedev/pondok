<?php

namespace App\Actions\Perizinan;

use App\Actions\Perizinan\Concerns\GuardsPerizinan;
use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveRequestVia;
use App\Models\HomecomingPeriod;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pulang massal (F024 D7): izin PULANG `disetujui` untuk santri aktif mukim dalam cakupan pembuat.
 * Idempoten per (santri, periode). Santri yang masih punya izin berjalan dilewati (`skipped`) dan
 * ikut pada generate berikutnya setelah izinnya selesai.
 */
class GenerateHomecomingLeaves
{
    use GuardsPerizinan;

    /**
     * @return array{created: int, skipped: int}
     */
    public function handle(HomecomingPeriod $period, User $actor): array
    {
        $this->authorize($actor->can('generate', $period), 'homecoming_period');

        $type = LeaveType::query()->active()->where('code', LeaveType::CODE_HOMECOMING)->first();

        if (! $type instanceof LeaveType) {
            $this->fail('period', 'type_unavailable', section: 'homecoming_period');
        }

        return DB::transaction(function () use ($period, $actor, $type): array {
            $alreadyHas = LeaveRequest::withoutGlobalScopes()->where('homecoming_period_id', $period->id)->pluck('student_id')->all();

            $studentIds = Student::visibleStudentsFor($actor)
                ->active()
                ->resident()
                ->whereNotIn('id', $alreadyHas)
                ->pluck('id');

            $busy = LeaveRequest::withoutGlobalScopes()
                ->whereIn('student_id', $studentIds)
                ->whereIn('status', LeaveRequestStatus::ongoingValues())
                ->pluck('student_id')
                ->unique();

            $toCreate = $studentIds->diff($busy);

            foreach ($toCreate as $studentId) {
                (new LeaveRequest)->forceFill([
                    'student_id' => $studentId,
                    'leave_type_id' => $type->id,
                    'homecoming_period_id' => $period->id,
                    'requested_via' => LeaveRequestVia::Musyrif,
                    'requested_by' => $actor->id,
                    'reason' => $period->name,
                    'planned_departure' => CarbonImmutable::parse($period->depart_on)->startOfDay(),
                    'return_due' => self::returnDue($period),
                    'status' => LeaveRequestStatus::Approved,
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                ])->save();
            }

            $result = ['created' => $toCreate->count(), 'skipped' => $busy->count()];

            $this->log($period, $actor, 'generated', $result);

            return $result;
        });
    }

    /**
     * Batas kembali izin periode: akhir hari `return_on`.
     */
    public static function returnDue(HomecomingPeriod $period): CarbonImmutable
    {
        return CarbonImmutable::parse($period->return_on)->setTime(23, 59, 59);
    }
}
