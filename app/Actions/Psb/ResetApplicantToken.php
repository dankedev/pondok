<?php

namespace App\Actions\Psb;

use App\Models\Applicant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Token akses pendaftar: 12 karakter tanpa huruf/angka yang mirip (I, O, 0, 1),
 * ditampilkan `XXXX-XXXX-XXXX`. Yang disimpan hanya hash token ternormalisasi.
 *
 * Mengatur ulang token membatalkan sesi halaman status yang lama (sidik
 * token berubah). Token polos tidak pernah masuk log.
 */
class ResetApplicantToken
{
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const LENGTH = 12;

    public static function newToken(): string
    {
        $characters = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $characters .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return implode('-', str_split($characters, 4));
    }

    /**
     * Huruf besar, tanpa spasi dan tanda hubung.
     */
    public static function normalizeToken(string $token): string
    {
        return (string) preg_replace('/[\s-]+/', '', mb_strtoupper($token));
    }

    public static function hash(string $token): string
    {
        return Hash::make(self::normalizeToken($token));
    }

    /**
     * Ganti token pendaftar; kembalikan token polos untuk ditampilkan sekali.
     */
    public function handle(Applicant $applicant, User $by): string
    {
        $token = self::newToken();

        activity()->withoutLogs(function () use ($applicant, $token): void {
            $applicant->forceFill(['access_token_hash' => self::hash($token)])->save();
        });

        activity('psb')
            ->performedOn($applicant)
            ->causedBy($by)
            ->event('token_reset')
            ->log(__('pondok.applicant.activity_token_reset'));

        return $token;
    }
}
