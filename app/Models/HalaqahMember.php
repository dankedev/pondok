<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHalaqah;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Riwayat keanggotaan halaqah, interval [joined_on, left_on). `active_marker` diisi database.
 */
#[Fillable(['halaqah_id', 'student_id', 'joined_on', 'left_on'])]
class HalaqahMember extends Model
{
    use BelongsToHalaqah;

    protected function casts(): array
    {
        return [
            'joined_on' => 'date',
            'left_on' => 'date',
        ];
    }

    /**
     * Keanggotaan yang meliputi tanggal `$date` (Y-m-d). `whereDate` agar portabel: SQLite menyimpan
     * kolom tanggal ber-cast `date` beserta jamnya.
     */
    public function scopeCovering(Builder $query, string $date): void
    {
        $query->whereDate($this->qualifyColumn('joined_on'), '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull($this->qualifyColumn('left_on'))->orWhereDate($this->qualifyColumn('left_on'), '>', $date));
    }
}
