<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Foto dalam album galeri (F016), berkas di disk `public`.
 */
#[Fillable(['album_id', 'path', 'caption', 'sort_order'])]
class Photo extends Model
{
    public const SORT_ORDER_MAX = 32767;

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}
