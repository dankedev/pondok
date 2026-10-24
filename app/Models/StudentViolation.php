<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StudentViolationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Pelanggaran santri (F026), dicatat dan dibatalkan lewat `App\Actions\Kedisiplinan\*`. `points` adalah
 * snapshot poin jenis; `academic_year_id` diturunkan dari `occurred_on`. Tidak dihapus: catatan keliru
 * dibatalkan (`cancelled_at`) dan tidak dihitung (`countable`).
 *
 * User login hanya melihat catatan santri dalam `Student::visibleStudentsFor` (global scope `visible`,
 * sudah termasuk cakupan jenjang). Portal wali memakai `withoutGlobalScope('visible')`.
 */
#[Fillable(['student_id', 'violation_type_id', 'academic_year_id', 'occurred_on', 'points', 'description', 'recorded_by'])]
class StudentViolation extends Model
{
    /** @use HasFactory<StudentViolationFactory> */
    use HasFactory;

    public const DESCRIPTION_MAX_LENGTH = 1000;

    public const CANCEL_REASON_MAX_LENGTH = 255;

    protected static function booted(): void
    {
        static::addGlobalScope('visible', function (Builder $builder): void {
            $user = Auth::user();

            if ($user instanceof User) {
                $builder->visibleTo($user);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'points' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Disimpan `Y-m-d` tanpa jam agar perbandingan dengan tanggal tahun ajaran sama di semua database.
     *
     * @return Attribute<never, string>
     */
    protected function occurredOn(): Attribute
    {
        return Attribute::set(fn (mixed $value): string => CarbonImmutable::parse($value, config('app.timezone'))->toDateString());
    }

    public function student(): BelongsTo
    {
        // Santri yang diarsipkan (soft delete) tetap tampil di riwayat pelanggaran.
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function violationType(): BelongsTo
    {
        return $this->belongsTo(ViolationType::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * Catatan yang dihitung ke total poin (belum dibatalkan).
     *
     * @param  Builder<StudentViolation>  $query
     */
    public function scopeCountable(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('cancelled_at'));
    }

    /**
     * @param  Builder<StudentViolation>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn($query->qualifyColumn('student_id'), Student::visibleStudentsFor($user)->withTrashed()->select('id'));
    }

    public function isVisibleTo(User $user): bool
    {
        return static::withoutGlobalScope('visible')->visibleTo($user)->whereKey($this->getKey())->exists();
    }
}
