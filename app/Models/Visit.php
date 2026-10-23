<?php

namespace App\Models;

use App\Models\Concerns\HasUnitScope;
use Database\Factories\VisitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kunjungan wali / sambangan (F024), dicatat lewat `RecordVisit`/`UpdateVisit`. Tanpa hapus.
 * Cakupan jenjang dibaca lewat santri.
 */
#[Fillable(['student_id', 'guardian_id', 'visitor_name', 'relation', 'started_at', 'ended_at', 'items_note', 'recorded_by'])]
class Visit extends Model
{
    /** @use HasFactory<VisitFactory> */
    use HasFactory, HasUnitScope;

    public const TEXT_MAX_LENGTH = 1000;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Santri kunjungan ini ada dalam cakupan jenjang `$user`.
     */
    public function isVisibleTo(User $user): bool
    {
        $unitIds = $user->scopedUnitIds();

        return $unitIds === [] || Student::withoutUnitScope()->withTrashed()
            ->whereKey($this->student_id)
            ->whereIn('unit_id', $unitIds)
            ->exists();
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
