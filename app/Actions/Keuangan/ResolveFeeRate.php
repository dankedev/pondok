<?php

namespace App\Actions\Keuangan;

use App\Models\AcademicYear;
use App\Models\FeeRate;
use App\Models\FeeType;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;

/**
 * Memilih satu tarif yang berlaku untuk jenis biaya, tahun ajaran, dan santri.
 *
 * Urutan tingkat (paling spesifik menang): angkatan + asrama, angkatan,
 * jenjang + asrama, jenjang. Tingkat menang atas tahun berlaku. Di dalam satu
 * tingkat: gelombang yang cocok menang atas tarif tanpa gelombang, lalu
 * `starts_on` tahun berlaku terbaru yang sudah berlaku, lalu id terbesar.
 *
 * Murni baca dan tidak memakai cakupan jenjang user login. Tidak memeriksa
 * `is_active`, `frequency`, maupun `applies_to` jenis biaya; itu tugas pemanggil.
 */
class ResolveFeeRate
{
    public function handle(
        FeeType $feeType,
        AcademicYear $academicYear,
        int $unitId,
        ?int $cohortId = null,
        ?int $dormitoryId = null,
        ?int $admissionWaveId = null,
    ): ?FeeRate {
        $candidates = FeeRate::query()
            ->with('effectiveFromYear')
            ->where('fee_rates.fee_type_id', $feeType->id)
            ->where('fee_rates.unit_id', $unitId)
            ->whereHas('effectiveFromYear', fn (Builder $query) => $query->whereDate('starts_on', '<=', $academicYear->starts_on))
            ->where(fn (Builder $query) => $this->matchOrNull($query, 'fee_rates.cohort_id', $cohortId))
            ->where(fn (Builder $query) => $this->matchOrNull($query, 'fee_rates.dormitory_id', $dormitoryId))
            ->where(fn (Builder $query) => $this->matchOrNull($query, 'fee_rates.admission_wave_id', $admissionWaveId))
            ->get();

        return $candidates
            ->sort(fn (FeeRate $a, FeeRate $b): int => $this->rank($a) <=> $this->rank($b))
            ->first();
    }

    public function forStudent(FeeType $feeType, Student $student, AcademicYear $academicYear): ?FeeRate
    {
        return $this->handle(
            $feeType,
            $academicYear,
            $student->unit_id,
            $student->cohort_id,
            $student->activeRoom?->dormitory_id,
        );
    }

    /**
     * Nilai null hanya mencocokkan baris NULL; nilai terisi mencocokkan nilai itu atau NULL.
     */
    private function matchOrNull(Builder $query, string $column, ?int $value): void
    {
        $query->whereNull($column);

        if ($value !== null) {
            $query->orWhere($column, $value);
        }
    }

    /**
     * Peringkat urut naik: tingkat spesifisitas, gelombang, tahun berlaku terbaru, id terbesar.
     *
     * @return array{int, int, int, int}
     */
    private function rank(FeeRate $rate): array
    {
        $level = match (true) {
            $rate->cohort_id !== null && $rate->dormitory_id !== null => 1,
            $rate->cohort_id !== null => 2,
            $rate->dormitory_id !== null => 3,
            default => 4,
        };

        return [
            $level,
            $rate->admission_wave_id !== null ? 0 : 1,
            -$rate->effectiveFromYear->starts_on->getTimestamp(),
            -$rate->id,
        ];
    }
}
