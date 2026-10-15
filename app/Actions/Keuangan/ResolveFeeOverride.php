<?php

namespace App\Actions\Keuangan;

use App\Models\FeeOverride;
use App\Models\FeeType;
use App\Models\Student;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Memilih satu keringanan yang aktif di bulan tagihan: masa berlakunya
 * beririsan dengan bulan `$period` (tanpa pro-rata). Bila ada lebih dari satu
 * (keringanan lama berakhir dan yang baru mulai di bulan yang sama),
 * `valid_from` terbaru menang, lalu id terbesar.
 *
 * Murni baca dan tidak memakai cakupan jenjang user login. Status aktif
 * program beasiswa tidak memengaruhi keringanan yang sudah ada.
 */
class ResolveFeeOverride
{
    public function forStudent(Student $student, FeeType $feeType, CarbonInterface $period): ?FeeOverride
    {
        return $this->resolve(FeeOverride::query()->where('student_id', $student->id), $feeType, $period);
    }

    /**
     * Keringanan pendaftar PSB (F010-F012), dicari lewat `applicant_id`.
     */
    public function forApplicant(int $applicantId, FeeType $feeType, CarbonInterface $period): ?FeeOverride
    {
        return $this->resolve(FeeOverride::query()->where('applicant_id', $applicantId), $feeType, $period);
    }

    /**
     * @param  Builder<FeeOverride>  $query
     */
    private function resolve(Builder $query, FeeType $feeType, CarbonInterface $period): ?FeeOverride
    {
        $month = CarbonImmutable::instance($period);

        return $query
            ->where('fee_type_id', $feeType->id)
            ->activeBetween($month->startOfMonth(), $month->endOfMonth())
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->first();
    }
}
