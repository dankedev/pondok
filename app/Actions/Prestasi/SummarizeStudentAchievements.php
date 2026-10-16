<?php

namespace App\Actions\Prestasi;

use App\Models\Achievement;
use App\Models\Scopes\UnitScope;
use App\Models\Student;

/**
 * Ringkasan prestasi satu santri: total, tingkat tertinggi (urutan
 * `Achievement::LEVELS`, dihitung di PHP), per tingkat, dan per kategori.
 * Bawaan hanya prestasi terverifikasi; `unverified` selalu jumlah yang belum
 * diverifikasi. Tanpa cakupan jenjang karena pemanggil sudah memegang santrinya.
 */
class SummarizeStudentAchievements
{
    /**
     * @return array{total: int, unverified: int, highest_level: ?string, by_level: array<string, int>, by_category: array<string, int>}
     */
    public function handle(Student $student, ?int $academicYearId = null, bool $verifiedOnly = true): array
    {
        $rows = $student->achievements()
            ->withoutGlobalScope(UnitScope::class)
            ->when($academicYearId !== null, fn ($query) => $query->where('achievements.academic_year_id', $academicYearId))
            ->get(['achievements.id', 'achievements.category', 'achievements.level', 'achievements.verified_by']);

        $counted = $verifiedOnly ? $rows->whereNotNull('verified_by') : $rows;
        $byLevel = array_fill_keys(Achievement::LEVELS, 0);
        $byCategory = array_fill_keys(Achievement::CATEGORIES, 0);

        foreach ($counted as $achievement) {
            if (array_key_exists($achievement->level, $byLevel)) {
                $byLevel[$achievement->level]++;
            }

            if (array_key_exists($achievement->category, $byCategory)) {
                $byCategory[$achievement->category]++;
            }
        }

        return [
            'total' => $counted->count(),
            'unverified' => $rows->whereNull('verified_by')->count(),
            'highest_level' => self::highestLevel(array_keys(array_filter($byLevel))),
            'by_level' => $byLevel,
            'by_category' => $byCategory,
        ];
    }

    /**
     * Tingkat tertinggi menurut urutan `Achievement::LEVELS`, null bila kosong.
     *
     * @param  iterable<string>  $levels
     */
    public static function highestLevel(iterable $levels): ?string
    {
        $highest = null;

        foreach ($levels as $level) {
            $position = array_search($level, Achievement::LEVELS, true);

            if ($position !== false && ($highest === null || $position > array_search($highest, Achievement::LEVELS, true))) {
                $highest = $level;
            }
        }

        return $highest;
    }
}
