<?php

namespace App\Actions\Psb;

use App\Models\AdmissionWave;
use App\Models\Applicant;
use LogicException;

/**
 * Nomor pendaftaran `PSB-{YYYY}-{NNNN}`. `YYYY` = tahun mulai tahun ajaran
 * tujuan gelombang; `NNNN` urut per `YYYY` dari semua jenjang, termasuk
 * pendaftar terhapus (nomor tidak pernah dipakai ulang).
 */
class GenerateApplicantNumber
{
    public const MAX_SEQUENCE = 9999;

    public function handle(AdmissionWave $wave): string
    {
        $prefix = 'PSB-'.$wave->academicYear->starts_on->format('Y').'-';

        $last = Applicant::withoutGlobalScopes()
            ->where('registration_no', 'like', $prefix.'%')
            ->max('registration_no');

        $sequence = $last === null ? 1 : (int) substr((string) $last, strlen($prefix)) + 1;

        if ($sequence > self::MAX_SEQUENCE) {
            throw new LogicException("Nomor pendaftaran {$prefix} melewati ".self::MAX_SEQUENCE.'.');
        }

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
