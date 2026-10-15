<?php

namespace App\Actions\Psb;

use App\Models\AcademicYear;
use App\Models\AdmissionWave;
use App\Models\Applicant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Metrik dashboard PSB (F011): pendaftar tahun ajaran tujuan gelombang
 * terbaru (berdasarkan `academic_years.starts_on`), tanpa `draft` dan tanpa
 * yang terhapus, dalam cakupan jenjang user login.
 */
class SummarizeApplicants
{
    /** Urutan status yang ditampilkan. */
    public const STATUSES = [
        Applicant::STATUS_SUBMITTED,
        Applicant::STATUS_VERIFIED,
        Applicant::STATUS_ACCEPTED,
        Applicant::STATUS_RESERVE,
        Applicant::STATUS_REJECTED,
    ];

    /**
     * @return array{academic_year: AcademicYear|null, total: int, by_status: array<string, int>, last_7_days: int}
     */
    public function handle(?CarbonInterface $today = null): array
    {
        $byStatus = array_fill_keys(self::STATUSES, 0);
        $academicYear = AcademicYear::query()
            ->whereIn('id', AdmissionWave::query()->select('academic_year_id'))
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();

        if ($academicYear === null) {
            return ['academic_year' => null, 'total' => 0, 'by_status' => $byStatus, 'last_7_days' => 0];
        }

        $query = fn () => Applicant::query()
            ->whereIn('admission_wave_id', AdmissionWave::query()->select('id')->where('academic_year_id', $academicYear->id))
            ->whereIn('status', self::STATUSES);

        $counts = $query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        foreach ($counts as $status => $count) {
            $byStatus[$status] = (int) $count;
        }

        $since = CarbonImmutable::instance($today ?? now())
            ->setTimezone(config('app.timezone'))
            ->startOfDay()
            ->subDays(6);

        return [
            'academic_year' => $academicYear,
            'total' => array_sum($byStatus),
            'by_status' => $byStatus,
            'last_7_days' => $query()->where('created_at', '>=', $since)->count(),
        ];
    }
}
