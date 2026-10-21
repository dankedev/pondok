<?php

namespace App\Actions\Psb;

use App\Models\Applicant;
use App\Models\Guardian;

/**
 * Wali santri hasil daftar ulang. Wali lama dipakai ulang (tanpa diubah) hanya
 * bila nomor HP cocok dalam varian `08…`/`628…`/`+628…` **dan** nama sama
 * (tanpa beda huruf besar dan spasi); kandidat ber-id terkecil menang. Selain
 * itu dibuat wali baru: wali ganda lebih aman daripada salah menggabungkan
 * keluarga. Dipanggil di dalam transaksi `ReRegisterApplicant`; pemanggil wajib
 * memegang kunci `lockKey()` agar dua daftar ulang dengan HP wali sama tidak
 * membuat wali ganda. Kunci hanya efektif bila store cache terbagi antarproses
 * (`database` atau `file` di satu server), bukan `array`.
 */
class ResolveApplicantGuardian
{
    /**
     * @return array{guardian: Guardian, reused: bool}
     */
    public function handle(Applicant $applicant): array
    {
        $name = self::normalizeName((string) $applicant->guardian_name);
        $phone = (string) $applicant->guardian_phone;

        $existing = Guardian::query()
            ->whereIn('phone', self::phoneVariants($phone))
            ->orderBy('id')
            ->get(['id', 'name', 'phone'])
            ->first(fn (Guardian $guardian): bool => self::normalizeName((string) $guardian->name) === $name);

        if ($existing !== null) {
            return ['guardian' => Guardian::query()->findOrFail($existing->id), 'reused' => true];
        }

        return [
            'guardian' => Guardian::query()->create(['name' => $applicant->guardian_name, 'phone' => $phone]),
            'reused' => false,
        ];
    }

    /**
     * Nama kunci per HP kanonik (`+62…`/`62…` → `0…`), sama untuk semua varian.
     */
    public static function lockKey(?string $phone): string
    {
        return 'psb:guardian:'.preg_replace('/^\+?62/', '0', trim((string) $phone));
    }

    /**
     * @return list<string>
     */
    public static function phoneVariants(string $phone): array
    {
        if (! str_starts_with($phone, '08')) {
            return [$phone];
        }

        $local = substr($phone, 1);

        return [$phone, "62{$local}", "+62{$local}"];
    }

    public static function normalizeName(string $name): string
    {
        return (string) preg_replace('/\s+/u', ' ', mb_strtolower(trim($name)));
    }
}
