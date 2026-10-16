<?php

namespace App\Actions\Prestasi;

use App\Models\Achievement;
use App\Models\Student;
use Illuminate\Database\Query\Expression;

/**
 * Rekap prestasi per tahun ajaran: total, keikutsertaan santri, dan matriks
 * kategori × tingkat. Bawaan hanya prestasi terverifikasi (angka resmi).
 *
 * Prestasi mengikuti cakupan jenjang `Achievement`, keikutsertaan mengikuti
 * cakupan `Student` (termasuk santri terarsip). Dengan `$unitId`, hanya
 * prestasi yang punya peserta di jenjang itu dan hanya peserta jenjang itu
 * yang dihitung. Dua query, tanpa SQL mentah untuk kolom `level`.
 */
class SummarizeAchievements
{
    /**
     * @return array{total: int, participations: int, by_category: array<string, int>, by_level: array<string, int>, matrix: array<string, array<string, int>>}
     */
    public function handle(?int $academicYearId, ?int $unitId = null, bool $verifiedOnly = true): array
    {
        $achievements = Achievement::query()
            ->when($verifiedOnly, fn ($query) => $query->verified())
            ->when($academicYearId !== null, fn ($query) => $query->forAcademicYear($academicYearId))
            ->when($unitId !== null, fn ($query) => $query->forUnit($unitId));

        $grouped = (clone $achievements)->toBase()->select(['category', 'level'])->groupBy(['category', 'level']);
        $grouped->addSelect(new Expression('count(*) as aggregate'));

        $matrix = array_fill_keys(Achievement::CATEGORIES, array_fill_keys(Achievement::LEVELS, 0));

        foreach ($grouped->get() as $row) {
            if (isset($matrix[$row->category][$row->level])) {
                $matrix[$row->category][$row->level] = (int) $row->aggregate;
            }
        }

        $participations = Student::query()
            ->withTrashed()
            ->join('achievement_student', 'achievement_student.student_id', '=', 'students.id')
            ->whereIn('achievement_student.achievement_id', $achievements->select('achievements.id'))
            ->when($unitId !== null, fn ($query) => $query->where('students.unit_id', $unitId))
            ->count();

        $byLevel = array_fill_keys(Achievement::LEVELS, 0);

        foreach ($matrix as $levels) {
            foreach ($levels as $level => $count) {
                $byLevel[$level] += $count;
            }
        }

        $byCategory = array_map('array_sum', $matrix);

        return [
            'total' => array_sum($byCategory),
            'participations' => $participations,
            'by_category' => $byCategory,
            'by_level' => $byLevel,
            'matrix' => $matrix,
        ];
    }
}
