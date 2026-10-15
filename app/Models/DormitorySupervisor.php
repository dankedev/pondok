<?php

namespace App\Models;

use Database\Factories\DormitorySupervisorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penugasan kepala asrama atau musyrif per tahun ajaran. `room_id` kosong
 * berarti mengawasi seluruh asrama.
 */
#[Fillable(['dormitory_id', 'room_id', 'staff_id', 'academic_year_id', 'role', 'start_on', 'end_on'])]
class DormitorySupervisor extends Model
{
    /** @use HasFactory<DormitorySupervisorFactory> */
    use HasFactory;

    public const ROLE_HEAD = 'kepala_asrama';

    public const ROLE_SUPERVISOR = 'musyrif';

    public const ROLES = [self::ROLE_HEAD, self::ROLE_SUPERVISOR];

    protected function casts(): array
    {
        return [
            'start_on' => 'date',
            'end_on' => 'date',
        ];
    }

    public function dormitory(): BelongsTo
    {
        return $this->belongsTo(Dormitory::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Penugasan yang masih berjalan di tahun ajaran aktif.
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('end_on'))
            ->whereIn($this->qualifyColumn('academic_year_id'), AcademicYear::query()->active()->select('id'));
    }
}
