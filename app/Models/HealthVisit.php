<?php

namespace App\Models;

use App\Models\Concerns\HasUnitScope;
use Database\Factories\HealthVisitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Kunjungan kesehatan santri (F031), dicatat lewat `App\Actions\Kesehatan\*`. Keluhan, tindakan,
 * dan catatan terenkripsi sehingga tidak bisa dicari. Tanpa soft delete; hapus hanya super-admin.
 *
 * Cakupan jenjang dibaca lewat santri. Selain itu user login tanpa `view-any:student` (musyrif)
 * hanya melihat catatan santri binaannya (`visibleStudents`).
 */
#[Fillable(['student_id', 'visited_at', 'complaint', 'treatment', 'note', 'needs_referral', 'needs_leave', 'recorded_by'])]
class HealthVisit extends Model
{
    /** @use HasFactory<HealthVisitFactory> */
    use HasFactory, HasUnitScope;

    public const TEXT_MAX_LENGTH = 1000;

    protected static function booted(): void
    {
        static::addGlobalScope('supervised', function (Builder $builder): void {
            $user = Auth::user();

            if ($user instanceof User && ! $user->can('view-any:student')) {
                $builder->visibleTo($user);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
            'complaint' => 'encrypted',
            'treatment' => 'encrypted',
            'note' => 'encrypted',
            'needs_referral' => 'boolean',
            'needs_leave' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        // Santri yang terhapus (soft delete) tetap tampil di riwayat kesehatan.
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Santri yang catatan kesehatannya boleh dilihat dan dicatat `$user` (`Student::visibleStudentsFor`).
     * Kunci bypass sengaja `view-any:student`, bukan `view-any:dormitory` seperti `RoomAssignment`:
     * data kesehatan melekat pada santri, dan peran tanpa izin santri tetap terbatas (fail-closed).
     *
     * @return Builder<Student>
     */
    public static function visibleStudents(User $user): Builder
    {
        return Student::visibleStudentsFor($user);
    }

    /**
     * @param  Builder<HealthVisit>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn($this->qualifyColumn('student_id'), static::visibleStudents($user)->withTrashed()->select('id'));
    }

    public function isVisibleTo(User $user): bool
    {
        return static::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }

    /**
     * Jenjang dibaca lewat santri (termasuk yang diarsipkan).
     *
     * @param  list<int>  $unitIds
     */
    public function applyUnitScope(Builder $builder, array $unitIds): void
    {
        $builder->whereIn(
            $this->qualifyColumn('student_id'),
            Student::withoutUnitScope()->withTrashed()->whereIn('unit_id', $unitIds)->select('id'),
        );
    }
}
