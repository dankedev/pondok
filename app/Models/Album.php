<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Album galeri foto (F016). Foto disimpan di disk `public` pada `albums/{id}/`.
 * Dihapus permanen beserta foto dan berkasnya lewat `DeleteAlbum`.
 */
#[Fillable(['category_id', 'title', 'slug', 'description'])]
class Album extends Model
{
    public const DIRECTORY_PREFIX = 'albums';

    public const SLUG_MAX_LENGTH = 200;

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Foto pertama (urutan terkecil, lalu id terkecil) sebagai sampul album.
     */
    public function coverPhoto(): HasOne
    {
        return $this->hasOne(Photo::class)->ofMany(['sort_order' => 'min', 'id' => 'min']);
    }

    public function directory(): string
    {
        return self::DIRECTORY_PREFIX.'/'.$this->getKey();
    }
}
