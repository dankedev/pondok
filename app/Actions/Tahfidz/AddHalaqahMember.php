<?php

namespace App\Actions\Tahfidz;

use App\Actions\Tahfidz\Concerns\GuardsTahfidz;
use App\Models\Halaqah;
use App\Models\HalaqahMember;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Menambahkan santri ke halaqah. Keanggotaan aktif di halaqah lain ditutup pada tanggal masuk
 * (pindah halaqah); sudah aktif di halaqah yang sama mengembalikan baris yang ada.
 */
class AddHalaqahMember
{
    use GuardsTahfidz;

    public function handle(User $actor, Halaqah $halaqah, Student $student, CarbonInterface $joinedOn): HalaqahMember
    {
        $this->authorize($actor, 'update:halaqah');
        $joinedOn = $joinedOn->toImmutable()->startOfDay();
        $halaqah->loadMissing('academicYear');

        if (! $halaqah->is_active) {
            $this->fail('halaqah_id', 'halaqah_inactive');
        }

        if ($joinedOn->gt(today()) || $joinedOn->lt($halaqah->academicYear->starts_on) || $joinedOn->gt($halaqah->academicYear->ends_on)) {
            $this->fail('joined_on', 'joined_outside_year');
        }

        return DB::transaction(function () use ($halaqah, $student, $joinedOn): HalaqahMember {
            $student = Student::withoutUnitScope()->withTrashed()->lockForUpdate()->findOrFail($student->id);
            $this->guardStudent($halaqah, $student);

            $current = HalaqahMember::withoutUnitScope()
                ->where('student_id', $student->id)
                ->whereNull('left_on')
                ->first();

            if ($current?->halaqah_id === $halaqah->id) {
                return $current;
            }

            if ($current !== null) {
                if ($joinedOn->lt($current->joined_on)) {
                    $this->fail('joined_on', 'joined_before_current');
                }

                // Tutup dulu baris lama agar unique `active_marker` tidak bentrok.
                $current->update(['left_on' => $joinedOn]);
            }

            return HalaqahMember::query()->create([
                'halaqah_id' => $halaqah->id,
                'student_id' => $student->id,
                'joined_on' => $joinedOn,
            ]);
        });
    }

    private function guardStudent(Halaqah $halaqah, Student $student): void
    {
        if (! $student->isActive() || $student->trashed()) {
            $this->fail('student_id', 'student_inactive');
        }

        if ($student->gender !== $halaqah->gender) {
            $this->fail('student_id', 'gender_mismatch');
        }

        if ($halaqah->unit_id !== null && $student->unit_id !== $halaqah->unit_id) {
            $this->fail('student_id', 'unit_mismatch');
        }
    }
}
