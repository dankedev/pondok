<?php

namespace App\Models;

use App\Models\Concerns\HasUnitScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kelompok setoran hafalan per tahun ajaran. `unit_id` kosong = lintas jenjang.
 */
#[Fillable(['academic_year_id', 'unit_id', 'teacher_staff_id', 'name', 'gender', 'schedule', 'is_active'])]
class Halaqah extends Model
{
    use HasUnitScope;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'teacher_staff_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(HalaqahMember::class);
    }

    /**
     * Halaqah lintas jenjang terlihat oleh semua cakupan jenjang.
     *
     * @param  list<int>  $unitIds
     */
    public function applyUnitScope(Builder $builder, array $unitIds): void
    {
        $builder->where(fn (Builder $query) => $query
            ->whereIn($this->qualifyColumn('unit_id'), $unitIds)
            ->orWhereNull($this->qualifyColumn('unit_id')));
    }

    /**
     * Pengelola halaqah (`update:halaqah`) melihat semua; selain itu hanya halaqah yang ia ampu.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can('update:halaqah')) {
            return;
        }

        $query->whereIn($this->qualifyColumn('teacher_staff_id'), Staff::query()->where('user_id', $user->id)->select('id'));
    }

    public function isVisibleTo(User $user): bool
    {
        return static::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }
}
