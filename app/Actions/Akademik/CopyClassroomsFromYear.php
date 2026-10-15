<?php

namespace App\Actions\Akademik;

use App\Models\AcademicYear;
use App\Models\Classroom;
use Illuminate\Support\Facades\DB;

/**
 * Menyalin daftar rombel dari satu tahun ajaran ke tahun ajaran lain.
 *
 * Wali kelas tidak ikut disalin karena bisa berganti tiap tahun. Rombel
 * yang namanya sudah ada di tahun tujuan dilewati.
 */
class CopyClassroomsFromYear
{
    /**
     * @param  list<int>|null  $unitIds  Batasi ke jenjang tertentu; null berarti semua jenjang.
     * @return int Jumlah rombel yang dibuat.
     */
    public function handle(AcademicYear $from, AcademicYear $to, ?array $unitIds = null): int
    {
        return DB::transaction(function () use ($from, $to, $unitIds): int {
            $existing = Classroom::query()
                ->where('academic_year_id', $to->id)
                ->get(['unit_id', 'name'])
                ->map(fn (Classroom $classroom): string => "{$classroom->unit_id}|{$classroom->name}")
                ->all();

            $created = 0;

            Classroom::query()
                ->where('academic_year_id', $from->id)
                ->when($unitIds !== null, fn ($query) => $query->whereIn('unit_id', $unitIds))
                ->orderBy('unit_id')
                ->orderBy('grade')
                ->orderBy('name')
                ->get()
                ->reject(fn (Classroom $classroom): bool => in_array("{$classroom->unit_id}|{$classroom->name}", $existing, true))
                ->each(function (Classroom $classroom) use ($to, &$created): void {
                    Classroom::query()->create([
                        'academic_year_id' => $to->id,
                        'unit_id' => $classroom->unit_id,
                        'grade' => $classroom->grade,
                        'name' => $classroom->name,
                        'gender' => $classroom->gender,
                        'capacity' => $classroom->capacity,
                    ]);
                    $created++;
                });

            return $created;
        });
    }
}
