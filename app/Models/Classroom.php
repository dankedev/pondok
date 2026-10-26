<?php

namespace App\Models;

use Database\Factories\ClassroomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rombel per tahun ajaran, misalnya 1A, 1B, 1C.
 */
#[Fillable(['academic_year_id', 'unit_id', 'grade', 'name', 'gender', 'homeroom_staff_id', 'capacity'])]
class Classroom extends Model
{
    /** @use HasFactory<ClassroomFactory> */
    use HasFactory;

    public const GENDER_MIXED = 'campur';

    protected function casts(): array
    {
        return [
            'grade' => 'integer',
            'capacity' => 'integer',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function homeroomStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'homeroom_staff_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * Roster rombel: santri `aktif` yang terdaftar di rombel ini, tanpa cakupan jenjang (pemanggil yang
     * memeriksa akses rombelnya), urut nama.
     *
     * @return Builder<Student>
     */
    public function rosterStudents(): Builder
    {
        return Student::withoutUnitScope()
            ->active()
            ->whereIn('id', Enrollment::query()->where('classroom_id', $this->id)->select('student_id'))
            ->orderBy('name');
    }

    public function acceptsGender(string $gender): bool
    {
        return $this->gender === self::GENDER_MIXED || $this->gender === $gender;
    }

    /**
     * Rombel yang boleh dilihat dan diisi `$user` pada absensi (F025) dan rapor diniyah (F027), dalam
     * cakupan jenjangnya: pemegang `view-any:student` (super-admin, admin-sekolah, kesantrian) melihat
     * semua rombel; selain itu hanya rombel yang wali kelasnya baris `staff` milik `$user`.
     *
     * @return Builder<Classroom>
     */
    public static function visibleTo(User $user): Builder
    {
        $unitIds = $user->scopedUnitIds();

        return static::query()
            ->when($unitIds !== [], fn (Builder $query) => $query->whereIn('unit_id', $unitIds))
            ->unless(
                $user->can('view-any:student'),
                fn (Builder $query) => $query->whereIn('homeroom_staff_id', Staff::query()->where('user_id', $user->id)->select('id')),
            );
    }
}
