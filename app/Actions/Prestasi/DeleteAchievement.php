<?php

namespace App\Actions\Prestasi;

use App\Actions\Prestasi\Concerns\FailsWithPrestasiErrors;
use App\Models\Achievement;
use App\Models\User;

/**
 * Menghapus prestasi yang belum diverifikasi, belum punya berita (termasuk
 * yang dihapus), dan belum punya dokumen. Baris peserta ikut terhapus
 * (`cascadeOnDelete`).
 */
class DeleteAchievement
{
    use FailsWithPrestasiErrors;

    public function handle(Achievement $achievement, User $actor): void
    {
        $this->ensureFullyVisible($achievement);

        if ($achievement->is_verified) {
            $this->fail('achievement', 'verified_locked');
        }

        if ($achievement->post()->exists()) {
            $this->fail('achievement', 'has_post');
        }

        if ($achievement->hasAnyDocuments()) {
            $this->fail('achievement', 'has_documents');
        }

        $achievement->delete();
    }
}
