<?php

namespace App\Actions\Psb;

use App\Models\Applicant;
use Illuminate\Support\Facades\Hash;

/**
 * Mencocokkan nomor pendaftaran + token di halaman status publik.
 *
 * Tanpa cakupan jenjang dan tanpa pendaftar terhapus. `Hash::check` selalu
 * dijalankan tepat sekali (terhadap hash dummy bila nomor tidak ada) agar
 * waktu respons tidak membocorkan nomor yang valid. Nomor tidak ada dan token
 * salah sama-sama menghasilkan null.
 */
class CheckApplicantCredentials
{
    private static ?string $dummyHash = null;

    public function handle(string $registrationNo, string $token): ?Applicant
    {
        $registrationNo = mb_strtoupper(trim($registrationNo));
        $token = ResetApplicantToken::normalizeToken($token);

        $applicant = $registrationNo === ''
            ? null
            : Applicant::withoutUnitScope()->where('registration_no', $registrationNo)->first();

        $matches = Hash::check($token, $applicant?->access_token_hash ?? self::dummyHash());

        return $applicant !== null && $token !== '' && $matches ? $applicant : null;
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make(ResetApplicantToken::newToken());
    }
}
