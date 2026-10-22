<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHalaqah;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Setoran hafalan sebagai rentang surah:ayat. Hanya dibuat dan dihapus lewat Action.
 */
#[Fillable(['student_id', 'halaqah_id', 'listener_staff_id', 'recorded_on', 'session', 'type', 'from_surah_id', 'from_ayah', 'to_surah_id', 'to_ayah', 'pages', 'grade', 'mistakes', 'note'])]
class MemorizationRecord extends Model
{
    use BelongsToHalaqah;

    public const SESSIONS = ['subuh', 'pagi', 'sore', 'maghrib', 'malam'];

    public const TYPES = ['ziyadah', 'murajaah', 'tasmi'];

    public const GRADES = ['mumtaz', 'jayyid_jiddan', 'jayyid', 'maqbul', 'ulang'];

    public const MAX_PAGES = 604;

    protected function casts(): array
    {
        return [
            'recorded_on' => 'date',
            'pages' => 'decimal:1',
            'from_surah_id' => 'integer',
            'from_ayah' => 'integer',
            'to_surah_id' => 'integer',
            'to_ayah' => 'integer',
            'mistakes' => 'integer',
        ];
    }

    public function listener(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'listener_staff_id');
    }

    public function fromSurah(): BelongsTo
    {
        return $this->belongsTo(QuranSurah::class, 'from_surah_id');
    }

    public function toSurah(): BelongsTo
    {
        return $this->belongsTo(QuranSurah::class, 'to_surah_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
