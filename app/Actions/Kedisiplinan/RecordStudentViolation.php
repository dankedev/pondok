<?php

namespace App\Actions\Kedisiplinan;

use App\Models\AcademicYear;
use App\Models\Student;
use App\Models\StudentViolation;
use App\Models\User;
use App\Models\ViolationType;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Mencatat (tanpa `$violation`) atau mengubah pelanggaran santri. Catatan baru hanya untuk santri aktif
 * dalam `Student::visibleStudentsFor($actor)`. Poin disalin dari jenis (snapshot) dan tahun ajaran
 * diturunkan dari tanggal kejadian. Ubah tidak mengganti santri maupun pencatat; catatan batal tidak diubah.
 */
class RecordStudentViolation
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, ?StudentViolation $violation = null): StudentViolation
    {
        $allowed = $violation ? $actor->can('update', $violation) : $actor->can('create', StudentViolation::class);

        throw_unless($allowed, AuthorizationException::class, __('pondok.kedisiplinan.errors.forbidden'));

        if ($violation?->isCancelled()) {
            $this->fail('cancelled_at', 'already_cancelled');
        }

        $valid = Validator::make($data, [
            'violation_type_id' => ['required', 'integer'],
            'occurred_on' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['nullable', 'string', 'max:'.StudentViolation::DESCRIPTION_MAX_LENGTH],
        ], [], __('pondok.kedisiplinan.fields'))->validate();

        $violation ??= $this->newViolation($actor, $data['student_id'] ?? null);
        $type = $this->type((int) $valid['violation_type_id'], $violation);
        $occurredOn = CarbonImmutable::parse($valid['occurred_on'])->toDateString();

        $violation->fill([
            'violation_type_id' => $type->id,
            'academic_year_id' => $this->academicYearId($occurredOn),
            'occurred_on' => $occurredOn,
            'description' => filled($valid['description'] ?? null) ? $valid['description'] : null,
        ]);

        if ($violation->isDirty('violation_type_id')) {
            $violation->points = $type->points;
        }

        if ($violation->exists && ! $violation->isDirty()) {
            return $violation;
        }

        return DB::transaction(function () use ($violation, $actor): StudentViolation {
            $event = $violation->exists ? 'updated' : 'created';
            $fields = array_keys($violation->getDirty());
            $violation->save();

            $this->log($violation, $actor, $event, $event === 'updated' ? ['fields' => $fields] : []);

            return $violation;
        });
    }

    private function newViolation(User $actor, mixed $studentId): StudentViolation
    {
        $isAvailable = is_numeric($studentId) && Student::visibleStudentsFor($actor)
            ->whereKey((int) $studentId)
            ->where('status', Student::STATUS_ACTIVE)
            ->exists();

        if (! $isAvailable) {
            $this->fail('student_id', 'student_unavailable');
        }

        return (new StudentViolation)->forceFill(['student_id' => (int) $studentId, 'recorded_by' => $actor->id]);
    }

    /**
     * Jenis aktif; jenis lama yang sudah nonaktif tetap boleh dipertahankan saat ubah.
     */
    private function type(int $typeId, StudentViolation $violation): ViolationType
    {
        $type = ViolationType::query()->find($typeId);

        if ($type === null || (! $type->is_active && $violation->violation_type_id !== $type->id)) {
            $this->fail('violation_type_id', 'type_unavailable');
        }

        return $type;
    }

    /**
     * Tahun ajaran yang memuat tanggal; bila tumpang tindih, yang mulai paling akhir.
     */
    private function academicYearId(string $date): int
    {
        $yearId = AcademicYear::query()
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->orderByDesc('starts_on')
            ->value('id');

        if ($yearId === null) {
            $this->fail('occurred_on', 'outside_academic_year');
        }

        return (int) $yearId;
    }

    private function fail(string $field, string $reason): never
    {
        throw ValidationException::withMessages([$field => __("pondok.kedisiplinan.errors.{$reason}")]);
    }

    /**
     * Activity log `kedisiplinan` tanpa teks keterangan.
     *
     * @param  array<string, mixed>  $properties
     */
    private function log(StudentViolation $violation, User $actor, string $event, array $properties): void
    {
        activity()
            ->inLog('kedisiplinan')
            ->performedOn($violation)
            ->causedBy($actor)
            ->event($event)
            ->withProperties([
                'student_id' => $violation->student_id,
                'violation_type_id' => $violation->violation_type_id,
                'occurred_on' => $violation->occurred_on->toDateString(),
                'points' => $violation->points,
                ...$properties,
            ])
            ->log($event);
    }
}
