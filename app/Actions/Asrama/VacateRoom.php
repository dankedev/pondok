<?php

namespace App\Actions\Asrama;

use App\Models\RoomAssignment;
use App\Models\Student;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mengakhiri penempatan kamar yang aktif sehingga santri menjadi nonmukim.
 * Barisnya tidak dihapus agar riwayat tetap ada.
 */
class VacateRoom
{
    public function handle(Student $student, ?CarbonInterface $endOn = null, ?string $note = null): RoomAssignment
    {
        $endOn = ($endOn ?? today())->toImmutable()->startOfDay();

        return DB::transaction(function () use ($student, $endOn, $note): RoomAssignment {
            $current = RoomAssignment::withoutUnitScope()
                ->where('student_id', $student->id)
                ->whereNull('end_on')
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                $this->fail('not_resident');
            }

            if ($endOn->lt($current->start_on)) {
                $this->fail('end_before_start');
            }

            $current->update(array_filter(
                ['end_on' => $endOn, 'note' => $note],
                fn (mixed $value): bool => $value !== null,
            ));

            return $current;
        });
    }

    private function fail(string $reason): never
    {
        throw ValidationException::withMessages([
            'end_on' => __("pondok.asrama.errors.{$reason}"),
        ]);
    }
}
