<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AttendanceSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * Absensi harian satu rombel pada satu tanggal (F025), dicatat lewat
 * `App\Actions\Absensi\RecordClassroomAttendance`. Tanpa soft delete dan tanpa hapus.
 *
 * User login hanya melihat sesi rombel dalam `visibleClassrooms()` (global scope `visible`,
 * sudah termasuk cakupan jenjang). Portal wali memakai `withoutGlobalScopes()`.
 */
#[Fillable(['classroom_id', 'held_on', 'recorded_by'])]
class AttendanceSession extends Model
{
    /** @use HasFactory<AttendanceSessionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope('visible', function (Builder $builder): void {
            $user = Auth::user();

            if ($user instanceof User) {
                $builder->whereIn($builder->qualifyColumn('classroom_id'), static::visibleClassrooms($user)->select('id'));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'held_on' => 'date',
        ];
    }

    /**
     * Disimpan `Y-m-d` tanpa jam agar unique (`classroom_id`, `held_on`) berlaku sama di SQLite,
     * MySQL, dan Postgres. Saat dibaca tetap tanggal (cast `date`).
     *
     * @return Attribute<never, string>
     */
    protected function heldOn(): Attribute
    {
        return Attribute::set(fn (mixed $value): string => CarbonImmutable::parse($value, config('app.timezone'))->toDateString());
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /**
     * Rombel yang absensinya boleh dilihat dan dicatat `$user` (F025 D2); sumbernya `Classroom::visibleTo()`.
     *
     * @return Builder<Classroom>
     */
    public static function visibleClassrooms(User $user): Builder
    {
        return Classroom::visibleTo($user);
    }
}
