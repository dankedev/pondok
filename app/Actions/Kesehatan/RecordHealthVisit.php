<?php

namespace App\Actions\Kesehatan;

use App\Actions\Kesehatan\Concerns\GuardsHealthVisit;
use App\Models\HealthVisit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mencatat kunjungan kesehatan santri aktif yang terlihat oleh pencatat (`HealthVisit::visibleStudents`).
 */
class RecordHealthVisit
{
    use GuardsHealthVisit;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data): HealthVisit
    {
        $this->authorize($actor->can('create', HealthVisit::class));

        $valid = $this->validated($data);
        $studentId = $data['student_id'] ?? null;

        $isAvailable = is_numeric($studentId) && HealthVisit::visibleStudents($actor)
            ->whereKey((int) $studentId)
            ->where('status', Student::STATUS_ACTIVE)
            ->exists();

        if (! $isAvailable) {
            $this->fail('student_id', 'student_unavailable');
        }

        return DB::transaction(function () use ($actor, $valid, $studentId): HealthVisit {
            $visit = new HealthVisit;
            $visit->forceFill([...$valid, 'student_id' => (int) $studentId, 'recorded_by' => $actor->id])->save();

            $this->log($visit, $actor, 'created');

            return $visit;
        });
    }
}
