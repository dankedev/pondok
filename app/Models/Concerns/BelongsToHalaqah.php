<?php

namespace App\Models\Concerns;

use App\Models\Halaqah;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Data tahfidz milik santri yang dicatat di sebuah halaqah (setoran, ujian). Cakupan jenjang
 * dibaca lewat santri; visibilitas mengikuti `Halaqah::visibleTo()`.
 */
trait BelongsToHalaqah
{
    use HasUnitScope;

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function halaqah(): BelongsTo
    {
        return $this->belongsTo(Halaqah::class);
    }

    /**
     * @param  list<int>  $unitIds
     */
    public function applyUnitScope(Builder $builder, array $unitIds): void
    {
        $builder->whereIn(
            $this->qualifyColumn('student_id'),
            Student::withoutUnitScope()->withTrashed()->whereIn('unit_id', $unitIds)->select('id'),
        );
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn($this->qualifyColumn('halaqah_id'), Halaqah::query()->visibleTo($user)->select('id'));
    }

    public function isVisibleTo(User $user): bool
    {
        return static::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }
}
