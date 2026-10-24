<?php

namespace App\Actions\Kedisiplinan;

use App\Models\DisciplineLevel;
use App\Models\Student;
use App\Models\StudentViolation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Rekap poin per santri dalam cakupan `$actor` untuk satu tahun ajaran: jumlah pelanggaran dan total
 * poin catatan yang belum batal, urut poin terbanyak, beserta label ambang (D7). Santri tanpa poin tidak ikut.
 */
class SummarizeDisciplinePoints
{
    /**
     * @return Collection<int, array{student_id: int, student: Student, violations_count: int, total_points: int, level: ?string}>
     */
    public function handle(User $actor, int $academicYearId, ?int $unitId = null): Collection
    {
        throw_unless($actor->can('viewAny', StudentViolation::class), AuthorizationException::class, __('pondok.kedisiplinan.errors.forbidden'));

        $counted = fn (Builder $query) => $query->countable()->where('academic_year_id', $academicYearId);
        $levels = DisciplineLevel::query()->orderByDesc('min_points')->get(['name', 'min_points']);

        return Student::visibleStudentsFor($actor)
            ->with('unit')
            ->when($unitId, fn (Builder $query) => $query->where('unit_id', $unitId))
            ->whereHas('violations', $counted)
            ->withCount(['violations' => $counted])
            ->withSum(['violations as total_points' => $counted], 'points')
            ->orderByDesc('total_points')
            ->orderBy('name')
            ->get()
            ->map(fn (Student $student): array => [
                'student_id' => $student->id,
                'student' => $student,
                'violations_count' => (int) $student->violations_count,
                'total_points' => (int) $student->total_points,
                'level' => $levels->first(fn (DisciplineLevel $level): bool => $level->min_points <= (int) $student->total_points)?->name,
            ]);
    }
}
