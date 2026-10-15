<?php

namespace App\Models;

use App\Models\Concerns\HasUnitScope;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Santri semua jenjang. `unit_id` diduplikasi dari `cohorts.unit_id` untuk
 * scope jenjang, dan hanya diubah lewat Action `ChangeStudentUnit`.
 */
#[Fillable([
    'unit_id', 'cohort_id', 'applicant_id', 'nis', 'nisn', 'name', 'gender',
    'birth_place', 'birth_date', 'address', 'status', 'entered_on', 'photo_path',
])]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory, HasUnitScope, SoftDeletes;

    public const STATUS_ACTIVE = 'aktif';

    public const STATUSES = ['aktif', 'lulus', 'keluar', 'pindah'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'entered_on' => 'date',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * Rombel santri di tahun ajaran yang sedang aktif.
     */
    public function currentEnrollment(): HasOne
    {
        return $this->hasOne(Enrollment::class)
            ->whereIn('academic_year_id', AcademicYear::query()->active()->select('id'));
    }

    public function guardians(): BelongsToMany
    {
        return $this->belongsToMany(Guardian::class, 'student_guardian')
            ->withPivot(['relation', 'is_billing_contact'])
            ->withTimestamps();
    }

    public function feeOverrides(): HasMany
    {
        return $this->hasMany(FeeOverride::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function roomAssignments(): HasMany
    {
        return $this->hasMany(RoomAssignment::class);
    }

    /**
     * Penempatan kamar yang sedang berjalan. Ada berarti santri mukim.
     */
    public function activeRoomAssignment(): HasOne
    {
        return $this->hasOne(RoomAssignment::class)->whereNull('end_on');
    }

    public function activeRoom(): HasOneThrough
    {
        return $this->hasOneThrough(Room::class, RoomAssignment::class, 'student_id', 'id', 'id', 'room_id')
            ->whereNull('room_assignments.end_on');
    }

    public function isResident(): bool
    {
        return $this->activeRoomAssignment()->exists();
    }

    public function scopeResident(Builder $query): void
    {
        $query->whereHas('activeRoomAssignment');
    }

    public function scopeNonResident(Builder $query): void
    {
        $query->whereDoesntHave('activeRoomAssignment');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
