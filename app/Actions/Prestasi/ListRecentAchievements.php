<?php

namespace App\Actions\Prestasi;

use App\Models\Achievement;
use Illuminate\Database\Eloquent\Collection;

/**
 * Prestasi terbaru (terscope) untuk widget dashboard: urut `achieved_on`
 * lalu id menurun, bawaan hanya yang terverifikasi. Peserta (terscope,
 * termasuk terarsip) dan tahun ajaran dimuat sekaligus.
 */
class ListRecentAchievements
{
    /**
     * @return Collection<int, Achievement>
     */
    public function handle(int $limit = 5, bool $verifiedOnly = true): Collection
    {
        return Achievement::query()
            ->when($verifiedOnly, fn ($query) => $query->verified())
            ->with(['students' => fn ($query) => $query->orderBy('name'), 'academicYear'])
            ->orderByDesc('achieved_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
