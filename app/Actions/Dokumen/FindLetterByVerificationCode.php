<?php

namespace App\Actions\Dokumen;

use App\Models\IssuedLetter;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mencari surat untuk halaman verifikasi publik, tanpa cakupan jenjang.
 * Kode dinormalisasi (huruf besar, tanpa spasi/strip); kode yang panjang
 * atau alfabetnya tidak sesuai langsung NULL tanpa query.
 */
class FindLetterByVerificationCode
{
    public function handle(string $code): ?IssuedLetter
    {
        $code = IssuedLetter::normalizeVerificationCode($code);

        if (strlen($code) !== IssuedLetter::CODE_LENGTH || strspn($code, IssuedLetter::CODE_ALPHABET) !== IssuedLetter::CODE_LENGTH) {
            return null;
        }

        return IssuedLetter::withoutGlobalScopes()
            ->with(['student' => fn (BelongsTo $query) => $query->withoutGlobalScopes()])
            ->where('verification_code', $code)
            ->first();
    }
}
