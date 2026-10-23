<?php

namespace App\Actions\Perizinan;

use App\Actions\Perizinan\Concerns\ValidatesVisit;
use App\Models\Student;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;

/**
 * Mencatat kunjungan wali (sambangan) untuk santri aktif dalam cakupan jenjang pencatat.
 */
class RecordVisit
{
    use ValidatesVisit;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data): Visit
    {
        $this->authorize($actor->can('create', Visit::class), 'visit');

        $studentId = $data['student_id'] ?? null;
        $unitIds = $actor->scopedUnitIds();

        $isAvailable = is_numeric($studentId) && Student::withoutUnitScope()
            ->when($unitIds !== [], fn ($query) => $query->whereIn('unit_id', $unitIds))
            ->whereKey((int) $studentId)
            ->where('status', Student::STATUS_ACTIVE)
            ->exists();

        if (! $isAvailable) {
            $this->fail('student_id', 'student_unavailable', section: 'visit');
        }

        $valid = $this->validatedVisit($data, (int) $studentId);

        return DB::transaction(function () use ($actor, $valid, $studentId): Visit {
            $visit = new Visit;
            $visit->forceFill([...$valid, 'student_id' => (int) $studentId, 'recorded_by' => $actor->id])->save();

            $this->log($visit, $actor, 'created', ['student_id' => $visit->student_id]);

            return $visit;
        });
    }
}
