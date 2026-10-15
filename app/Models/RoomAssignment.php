<?php

namespace App\Models;

use App\Models\Concerns\HasUnitScope;
use Database\Factories\RoomAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat kamar santri. Baris dengan `end_on` kosong adalah penempatan
 * aktif (santri mukim). Kolom `active_marker` diisi database dan tidak
 * pernah ditulis aplikasi.
 */
#[Fillable(['student_id', 'room_id', 'academic_year_id', 'start_on', 'end_on', 'note'])]
class RoomAssignment extends Model
{
    /** @use HasFactory<RoomAssignmentFactory> */
    use HasFactory, HasUnitScope;

    protected function casts(): array
    {
        return [
            'start_on' => 'date',
            'end_on' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        // Santri yang terhapus (soft delete) tetap tampil di riwayat kamar.
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Jenjang dibaca lewat santri.
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

    public function scopeActive(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('end_on'));
    }

    /**
     * Pemegang `view-any:dormitory` melihat semua penghuni. Selain itu
     * (musyrif, kepala asrama) hanya kamar yang ia awasi di tahun ajaran aktif.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can('view-any:dormitory')) {
            return;
        }

        $supervisions = DormitorySupervisor::query()
            ->active()
            ->whereIn('staff_id', Staff::query()->where('user_id', $user->id)->select('id'));

        $query->whereIn($this->qualifyColumn('room_id'), Room::query()
            ->select('rooms.id')
            ->where(function (Builder $rooms) use ($supervisions): void {
                $rooms
                    ->whereIn('rooms.dormitory_id', (clone $supervisions)->whereNull('room_id')->select('dormitory_id'))
                    ->orWhereIn('rooms.id', (clone $supervisions)->whereNotNull('room_id')->select('room_id'));
            }));
    }
}
