<?php

namespace App\Actions\Asrama;

use App\Models\AcademicYear;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Student;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menempatkan santri ke kamar. Bila santri sudah mukim di kamar lain,
 * penempatan lama ditutup dan baris baru dibuat (pindah kamar).
 *
 * Satu santri hanya punya satu penempatan aktif, dijaga oleh kunci baris
 * santri dan kamar di sini serta unique `active_marker` di database.
 */
class AssignRoom
{
    public function handle(Student $student, Room $room, ?CarbonInterface $startOn = null, ?string $note = null): RoomAssignment
    {
        $startOn = ($startOn ?? today())->toImmutable()->startOfDay();
        $room->loadMissing('dormitory');

        $this->guardStudent($student);
        $this->guardRoom($room);

        if ($room->dormitory->gender !== $student->gender) {
            $this->fail('gender_mismatch');
        }

        $academicYear = AcademicYear::current() ?? $this->fail('no_active_academic_year');

        return DB::transaction(function () use ($student, $room, $startOn, $note, $academicYear): RoomAssignment {
            // Urutan kunci selalu santri lalu kamar agar tidak deadlock.
            $lockedStudent = Student::withoutUnitScope()->withTrashed()->lockForUpdate()->findOrFail($student->id);
            $lockedRoom = Room::query()->with('dormitory')->lockForUpdate()->findOrFail($room->id);

            $this->guardStudent($lockedStudent);
            $this->guardRoom($lockedRoom);

            $current = RoomAssignment::withoutUnitScope()
                ->where('student_id', $lockedStudent->id)
                ->whereNull('end_on')
                ->first();

            if ($current?->room_id === $lockedRoom->id) {
                return $current;
            }

            if ($lockedRoom->occupiedCount() >= $lockedRoom->capacity) {
                $this->fail('room_full');
            }

            if ($current !== null) {
                if ($startOn->lt($current->start_on)) {
                    $this->fail('start_before_current');
                }

                // Tutup dulu baris lama agar unique `active_marker` tidak bentrok.
                $current->update(['end_on' => $startOn]);
            }

            return RoomAssignment::query()->create([
                'student_id' => $lockedStudent->id,
                'room_id' => $lockedRoom->id,
                'academic_year_id' => $academicYear->id,
                'start_on' => $startOn,
                'note' => $note,
            ]);
        });
    }

    private function guardStudent(Student $student): void
    {
        // Santri yang dihapus (soft delete) diperlakukan sebagai tidak aktif.
        if (! $student->isActive() || $student->trashed()) {
            $this->fail('student_inactive');
        }
    }

    private function guardRoom(Room $room): void
    {
        if (! $room->is_active) {
            $this->fail('room_inactive');
        }

        if (! $room->dormitory->is_active) {
            $this->fail('dormitory_inactive');
        }
    }

    private function fail(string $reason): never
    {
        throw ValidationException::withMessages([
            'room_id' => __("pondok.asrama.errors.{$reason}"),
        ]);
    }
}
