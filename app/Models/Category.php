<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kategori konten publik (F016): `post` untuk berita/kegiatan, `album` untuk galeri.
 * Kategori yang dipakai (post termasuk yang terhapus, atau album) tidak bisa dihapus
 * dan type-nya dikunci.
 */
#[Fillable(['type', 'name', 'slug'])]
class Category extends Model
{
    public const TYPE_POST = 'post';

    public const TYPE_ALBUM = 'album';

    public const TYPES = [self::TYPE_POST, self::TYPE_ALBUM];

    public const NAME_MAX_LENGTH = 100;

    public const SLUG_MAX_LENGTH = 120;

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function albums(): HasMany
    {
        return $this->hasMany(Album::class);
    }

    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('type', $type);
    }

    public function isInUse(): bool
    {
        return $this->posts()->withTrashed()->exists() || $this->albums()->exists();
    }
}
