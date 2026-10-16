<?php

namespace App\Actions\Prestasi;

use App\Actions\Prestasi\Concerns\FailsWithPrestasiErrors;
use App\Models\Achievement;
use App\Models\User;

/**
 * Memverifikasi prestasi sehingga dihitung di rekap resmi dan bisa dijadikan
 * berita. Pencatat boleh memverifikasi prestasinya sendiri bila berizin.
 */
class VerifyAchievement
{
    use FailsWithPrestasiErrors;

    public function handle(Achievement $achievement, User $verifier): Achievement
    {
        $this->ensureFullyVisible($achievement);

        if ($achievement->is_verified) {
            $this->fail('achievement', 'already_verified');
        }

        $achievement->forceFill(['verified_by' => $verifier->id])->save();

        return $achievement;
    }
}
